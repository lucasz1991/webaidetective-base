<?php

namespace App\Livewire\User;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Models\User;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

class NetworkMap extends Component
{
    private const GRAPH_NODE_CHUNK_SIZE = 300;

    private const GRAPH_EDGE_CHUNK_SIZE = 500;

    public ?int $primaryTrackedPersonId = null;

    public ?int $contextTrackedPersonId = null;

    public bool $embedded = false;

    public string $mapId = '';

    public ?string $graphToken = null;

    public array $graphStats = [
        'people' => 0,
        'nodes' => 0,
        'edges' => 0,
        'inferred' => 0,
        'trackedList' => 0,
    ];

    public static function graphCacheKey(int $userId, string $token): string
    {
        return 'network-map-graph:'.$userId.':'.$token;
    }

    public static function graphHashCacheKey(int $userId, string $token = '', ?int $contextTrackedPersonId = null): string
    {
        $scope = $contextTrackedPersonId ? ':person-'.$contextTrackedPersonId : ':global';

        if ($token === '') {
            return 'network-map-hash:'.$userId.$scope;
        }

        return 'network-map-hash:'.$userId.$scope.':'.$token;
    }

    public function mount(?int $trackedPersonId = null, bool $embedded = false): void
    {
        $this->contextTrackedPersonId = $trackedPersonId;
        $this->embedded = $embedded;
        $this->mapId = 'network-map-'.Str::uuid();
    }

    private function generateDataHash(Collection $trackedPeople): string
    {
        $data = $trackedPeople->map(function (TrackedPerson $person) {
            $currentRelationships = $person->currentInstagramProfile?->sourceRelationships ?? collect();
            $publicProfiles = $person->publicProfiles ?? collect();
            $publicProfileRelationships = $publicProfiles
                ->pluck('instagramProfile.sourceRelationships')
                ->flatten();

            return [
                'id' => $person->id,
                'instagram_username' => $person->instagram_username,
                'is_primary' => (bool) $person->is_primary,
                'updated_at' => $person->updated_at?->timestamp,
                'current_instagram_profile_id' => $person->current_instagram_profile_id,
                'public_profiles_count' => $publicProfiles->count(),
                'public_profiles_updated_at' => $publicProfiles->max('updated_at')?->timestamp,
                'inferred_connections_count' => $person->instagramInferredConnections->count(),
                'inferred_connections_updated_at' => $person->instagramInferredConnections->max('updated_at')?->timestamp,
                'current_relationships_count' => $currentRelationships->count(),
                'current_relationships_updated_at' => $currentRelationships->max('updated_at')?->timestamp,
                'public_profile_relationships_count' => $publicProfileRelationships->count(),
                'public_profile_relationships_updated_at' => $publicProfileRelationships->max('updated_at')?->timestamp,
                'latest_snapshot_updated' => $person->latestInstagramSnapshot?->updated_at?->timestamp,
            ];
        })->toArray();

        $data['graph_version'] = 2;
        $data['context_tracked_person_id'] = $this->contextTrackedPersonId;

        // Also include primary person flag
        $data['primary_person_id'] = $this->primaryTrackedPersonId;
        
        return hash('sha256', json_encode($data));
    }

    public function setPrimaryTrackedPerson($trackedPersonId): void
    {
        $user = Auth::user();
        $trackedPersonId = (int) $trackedPersonId;

        if (! $user || ! $user->trackedPeople()->whereKey($trackedPersonId)->exists()) {
            return;
        }

        DB::transaction(function () use ($user, $trackedPersonId): void {
            $user->trackedPeople()->update(['is_primary' => false]);
            $user->trackedPeople()->whereKey($trackedPersonId)->update(['is_primary' => true]);
        });

        $this->forgetGraphCache((int) $user->id);

        $this->primaryTrackedPersonId = $trackedPersonId;
        $this->graphToken = null;
        $this->graphStats = $this->emptyGraphStats($user->trackedPeople()->count());
        $this->dispatch('network-map-reset', mapId: $this->mapId);
        $this->prepareGraph();
    }

    public function prepareGraph(): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $trackedPeople = $this->loadTrackedPeopleForGraph($user);
        $this->primaryTrackedPersonId = $this->resolvePrimaryTrackedPersonId($trackedPeople);

        if ($trackedPeople->isEmpty()) {
            $this->graphToken = null;
            $this->graphStats = $this->emptyGraphStats();
            $this->dispatch('network-map-empty', mapId: $this->mapId, stats: $this->graphStats);

            return;
        }

        // Generate data hash for cache validation
        $dataHash = $this->generateDataHash($trackedPeople);
        $cachedToken = Cache::get(self::graphHashCacheKey((int) $user->id, contextTrackedPersonId: $this->contextTrackedPersonId));

        // If we have a cached token with the same data hash, reuse it
        if ($cachedToken && $dataHash === Cache::get(self::graphHashCacheKey((int) $user->id, $cachedToken, $this->contextTrackedPersonId))) {
            $cachedData = Cache::get(self::graphCacheKey((int) $user->id, $cachedToken));
            if ($cachedData) {
                $this->graphToken = $cachedToken;
                $this->graphStats = $cachedData['stats'] ?? $this->graphStats;
                $this->dispatch(
                    'network-map-graph-prepared',
                    mapId: $this->mapId,
                    token: $cachedToken,
                    chunkCount: count($cachedData['chunks'] ?? []),
                    chunkUrl: route('network.graph-chunk', ['token' => $cachedToken, 'chunk' => '__CHUNK__']),
                    stats: $this->graphStats,
                );
                return;
            }
        }

        $graph = $this->buildGraph($trackedPeople);
        $stats = $this->statsForGraph($trackedPeople, $graph);
        $chunks = $this->chunkGraph($graph);
        $token = (string) Str::uuid();

        // Cache both the graph data and the hash
        Cache::put(
            self::graphCacheKey((int) $user->id, $token),
            [
                'stats' => $stats,
                'chunks' => $chunks,
            ],
            now()->addMinutes(30),
        );
        
        // Cache the hash mapping for future validation
        Cache::put(
            self::graphHashCacheKey((int) $user->id, $token, $this->contextTrackedPersonId),
            $dataHash,
            now()->addMinutes(30),
        );
        
        // Store current token in primary cache key for quick lookup
        Cache::put(
            self::graphHashCacheKey((int) $user->id, contextTrackedPersonId: $this->contextTrackedPersonId),
            $token,
            now()->addMinutes(30),
        );

        $this->graphToken = $token;
        $this->graphStats = $stats;
        $this->dispatch(
            'network-map-graph-prepared',
            mapId: $this->mapId,
            token: $token,
            chunkCount: count($chunks),
            chunkUrl: route('network.graph-chunk', ['token' => $token, 'chunk' => '__CHUNK__']),
            stats: $stats,
        );
    }

    public function render()
    {
        $user = Auth::user();
        $trackedPeople = $user
            ? $user->trackedPeople()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
            : collect();

        $this->primaryTrackedPersonId = $this->resolvePrimaryTrackedPersonId($trackedPeople);
        $stats = $this->graphStats;
        $stats['people'] = $trackedPeople->count();

        $view = view('livewire.user.network-map', [
            'trackedPeople' => $trackedPeople,
            'stats' => $stats,
            'embedded' => $this->embedded,
        ]);

        return $this->embedded ? $view : $view->layout('layouts.app');
    }

    private function loadTrackedPeopleForGraph($user): Collection
    {
        return $user->trackedPeople()
            ->with([
                'currentInstagramProfile.sourceRelationships' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereIn('list_type', ['followers', 'following'])
                    ->with('relatedInstagramProfile'),
                'latestInstagramSnapshot',
                'publicProfiles.instagramProfile.sourceRelationships' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereIn('list_type', ['followers', 'following'])
                    ->with('relatedInstagramProfile'),
                'publicProfiles.latestInstagramConnectionScan',
                'instagramInferredConnections.publicProfile',
                'instagramInferredConnections.candidateInstagramProfile',
            ])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    private function emptyGraphStats(int $people = 0): array
    {
        return [
            'people' => $people,
            'nodes' => 0,
            'edges' => 0,
            'inferred' => 0,
            'trackedList' => 0,
        ];
    }

    private function statsForGraph(Collection $trackedPeople, array $graph): array
    {
        $edges = collect($graph['edges']);

        return [
            'people' => $trackedPeople->count(),
            'nodes' => count($graph['nodes']),
            'edges' => count($graph['edges']),
            'inferred' => $edges->where('type', 'inferred')->count(),
            'trackedList' => $edges->where('type', 'tracked-list')->count(),
        ];
    }

    private function chunkGraph(array $graph): array
    {
        $chunks = [];

        foreach (array_chunk(array_values($graph['nodes']), self::GRAPH_NODE_CHUNK_SIZE) as $nodes) {
            $chunks[] = [
                'stage' => 'nodes',
                'nodes' => $nodes,
                'edges' => [],
            ];
        }

        foreach (array_chunk(array_values($graph['edges']), self::GRAPH_EDGE_CHUNK_SIZE) as $edges) {
            $chunks[] = [
                'stage' => 'edges',
                'nodes' => [],
                'edges' => $edges,
            ];
        }

        if ($chunks === []) {
            $chunks[] = [
                'stage' => 'empty',
                'nodes' => [],
                'edges' => [],
            ];
        }

        return array_map(function (array $chunk, int $index) use ($chunks): array {
            return [
                ...$chunk,
                'index' => $index,
                'chunkCount' => count($chunks),
            ];
        }, $chunks, array_keys($chunks));
    }

    private function buildGraph(Collection $trackedPeople): array
    {
        $nodes = [];
        $edges = [];
        $primaryPerson = $trackedPeople->firstWhere('is_primary', true) ?: $trackedPeople->first();
        $focusedPeople = $primaryPerson ? collect([$primaryPerson]) : collect();
        $peopleByInstagram = $trackedPeople
            ->filter(fn (TrackedPerson $person): bool => filled($person->instagram_username))
            ->mapWithKeys(fn (TrackedPerson $person): array => [
                $this->normalizeUsername($person->instagram_username) => $person,
            ]);

        if ($primaryPerson) {
            $this->ensureTrackedPersonNode($nodes, $primaryPerson);
        }

        foreach ($focusedPeople as $person) {
            $sourceId = 'person-'.$person->id;

            foreach ($person->publicProfiles as $publicProfile) {
                if (! filled($publicProfile->username)) {
                    continue;
                }

                $profileUsername = $this->normalizeUsername($publicProfile->username);
                $targetPerson = $peopleByInstagram->get($profileUsername);
                $targetId = $targetPerson ? $this->ensureTrackedPersonNode($nodes, $targetPerson) : 'profile-'.$publicProfile->platform.'-'.$profileUsername;
                $linkedInstagramProfile = $publicProfile->instagramProfile;
                $profileImageUrl = $this->profileImageUrlForInstagramProfile($linkedInstagramProfile);

                if (! isset($nodes[$targetId])) {
                    $nodes[$targetId] = [
                        'id' => $targetId,
                        'type' => 'profile',
                        'label' => $publicProfile->display_name ?: $publicProfile->display_handle,
                        'handle' => $publicProfile->display_handle,
                        'username' => $profileUsername,
                        'platform' => $publicProfile->platform,
                        'imageUrl' => $profileImageUrl,
                        'hasImage' => filled($profileImageUrl),
                        'isPrimary' => false,
                        'role' => 'Bekanntes Profil',
                        'status' => $publicProfile->is_public ? 'public' : 'unknown',
                        'detail' => $publicProfile->relationship_label,
                        'isKnownProfile' => true,
                    ];
                } else {
                    $nodes[$targetId]['isKnownProfile'] = true;
                }

                foreach ($this->publicProfileEdges($sourceId, $targetId, $publicProfile->relationship_type) as $edge) {
                    $edges[$edge['id']] = [
                        ...$edge,
                        'type' => 'public-profile',
                        'label' => $publicProfile->relationship_label,
                        'sourceHandle' => $publicProfile->display_handle,
                    ];
                }
            }

            $nodesByInstagram = $this->indexGraphNodesByInstagramUsername($nodes);

            foreach ($person->instagramInferredConnections as $connection) {
                if (! filled($connection->candidate_username)) {
                    continue;
                }

                $candidateUsername = $this->normalizeUsername($connection->candidate_username);
                $candidatePerson = $peopleByInstagram->get($candidateUsername);
                $existingCandidateNode = $nodesByInstagram->get($candidateUsername);
                $candidateId = $candidatePerson
                    ? $this->ensureTrackedPersonNode($nodes, $candidatePerson)
                    : ($existingCandidateNode['id'] ?? 'profile-instagram-'.$candidateUsername);

                if (! isset($nodes[$candidateId])) {
                    $candidateImageUrl = $this->profileImageUrlForInstagramProfile($connection->candidateInstagramProfile);

                    $nodes[$candidateId] = [
                        'id' => $candidateId,
                        'type' => 'candidate',
                        'label' => $connection->candidate_display_name ?: '@'.$candidateUsername,
                        'handle' => '@'.$candidateUsername,
                        'username' => $candidateUsername,
                        'platform' => 'instagram',
                        'imageUrl' => $candidateImageUrl,
                        'hasImage' => filled($candidateImageUrl),
                        'isPrimary' => false,
                        'role' => 'Rekonstruierter Kandidat',
                        'status' => 'inferred',
                        'detail' => $connection->relationship_label,
                        'isKnownProfile' => false,
                    ];
                    $nodesByInstagram->put($candidateUsername, $nodes[$candidateId]);
                } elseif (($nodes[$candidateId]['type'] ?? null) !== 'person') {
                    $nodes[$candidateId]['detail'] = $this->mergeNodeDetail(
                        $nodes[$candidateId]['detail'] ?? null,
                        $connection->relationship_label,
                    );
                }

                if ($connection->relationship_type === 'suggestion_connection') {
                    $from = $sourceId;
                    $to = $candidateId;
                } else {
                    $isFollower = $connection->relationship_type === 'follows_target';
                    $from = $isFollower ? $candidateId : $sourceId;
                    $to = $isFollower ? $sourceId : $candidateId;
                }
                $edgeId = 'inferred-'.$connection->relationship_type.'-'.$from.'-'.$to;

                $edges[$edgeId] = [
                    'id' => $edgeId,
                    'from' => $from,
                    'to' => $to,
                    'type' => 'inferred',
                    'label' => $connection->relationship_label,
                    'sourceHandle' => $connection->source_public_username ? '@'.$connection->source_public_username : null,
                ];
            }
        }

        $nodesByInstagram = $this->indexGraphNodesByInstagramUsername($nodes);

        $this->addStoredInstagramProfileListEdges($primaryPerson, $peopleByInstagram, $nodesByInstagram, $nodes, $edges);
        $this->addTrackedRelationshipListEdges($primaryPerson, $peopleByInstagram, $nodesByInstagram, $nodes, $edges);
        $this->addObservedTrackedPersonConnectionsToPrimary($trackedPeople, $primaryPerson, $nodesByInstagram, $nodes, $edges);
        $this->addTrackedPersonProfileRelationships($trackedPeople, $nodesByInstagram, $nodes, $edges);

        return $this->applyLayout(array_values($nodes), array_values($edges));
    }

    private function ensureTrackedPersonNode(array &$nodes, TrackedPerson $person): string
    {
        $nodeId = 'person-'.$person->id;

        if (isset($nodes[$nodeId])) {
            return $nodeId;
        }

        $imageUrl = $this->profileImageUrlForPerson($person);

        $nodes[$nodeId] = [
            'id' => $nodeId,
            'type' => 'person',
            'label' => $person->display_name,
            'handle' => $person->instagram_username ? '@'.$person->instagram_username : '',
            'username' => $this->normalizeUsername($person->instagram_username),
            'imageUrl' => $imageUrl,
            'hasImage' => filled($imageUrl),
            'isPrimary' => (bool) $person->is_primary,
            'role' => $person->is_primary ? 'Hauptperson' : 'Beobachtete Person',
            'status' => $person->last_instagram_status_level ?: 'neutral',
            'detail' => $person->last_instagram_status_message ?: null,
            'isKnownProfile' => false,
        ];

        return $nodeId;
    }

    private function resolvePrimaryTrackedPersonId(Collection $trackedPeople): ?int
    {
        if ($trackedPeople->isEmpty()) {
            return null;
        }

        if ($this->contextTrackedPersonId) {
            $contextPerson = $trackedPeople->firstWhere('id', $this->contextTrackedPersonId);

            if ($contextPerson) {
                $this->primaryTrackedPersonId = (int) $contextPerson->id;

                $trackedPeople->each(function (TrackedPerson $person) use ($contextPerson): void {
                    $person->is_primary = (int) $person->id === (int) $contextPerson->id;
                });

                return (int) $contextPerson->id;
            }
        }

        $primary = $trackedPeople->firstWhere('is_primary', true);

        if ($primary) {
            if ($this->primaryTrackedPersonId !== $primary->id) {
                $this->primaryTrackedPersonId = $primary->id;
            }

            return $primary->id;
        }

        $fallbackId = $trackedPeople->first()?->id;

        if ($fallbackId) {
            $this->persistPrimaryTrackedPersonId($fallbackId);

            $trackedPeople->each(function (TrackedPerson $person) use ($fallbackId): void {
                $person->is_primary = $person->id === $fallbackId;
            });
        }

        return $fallbackId;
    }

    private function persistPrimaryTrackedPersonId(int $trackedPersonId): void
    {
        $user = Auth::user();

        if (! $user || ! $user->trackedPeople()->whereKey($trackedPersonId)->exists()) {
            return;
        }

        DB::transaction(function () use ($user, $trackedPersonId): void {
            $user->trackedPeople()->update(['is_primary' => false]);
            $user->trackedPeople()->whereKey($trackedPersonId)->update(['is_primary' => true]);
        });

        $this->primaryTrackedPersonId = $trackedPersonId;
    }

    private function addStoredInstagramProfileListEdges(
        ?TrackedPerson $primaryPerson,
        Collection $peopleByInstagram,
        Collection $nodesByInstagram,
        array &$nodes,
        array &$edges,
    ): void {
        if (! $primaryPerson || ! $this->shouldShowStoredListProfile($primaryPerson->currentInstagramProfile, $this->trackedPersonInstagramProfileIsPublic($primaryPerson))) {
            return;
        }

        $processedSources = [];

        $this->addInstagramProfileListRelationshipEdges(
            $primaryPerson->currentInstagramProfile,
            'person-'.$primaryPerson->id,
            $peopleByInstagram,
            $nodesByInstagram,
            $nodes,
            $edges,
            $processedSources,
        );
    }

    private function addInstagramProfileListRelationshipEdges(
        ?InstagramProfile $sourceProfile,
        string $sourceId,
        Collection $peopleByInstagram,
        Collection $nodesByInstagram,
        array &$nodes,
        array &$edges,
        array &$processedSources,
    ): void {
        if (! $sourceProfile) {
            return;
        }

        $sourceKey = $sourceProfile->id.'|'.$sourceId;

        if (isset($processedSources[$sourceKey])) {
            return;
        }

        $processedSources[$sourceKey] = true;
        $sourceHandle = $this->displayUsernameForInstagramProfile($sourceProfile);

        foreach ($sourceProfile->sourceRelationships as $relationship) {
            if (! $this->isActiveListRelationship($relationship)) {
                continue;
            }

            $relatedProfile = $relationship->relatedInstagramProfile;
            $targetId = $this->ensureInstagramProfileNode(
                $nodes,
                $nodesByInstagram,
                $peopleByInstagram,
                $relatedProfile,
                'Listeneintrag',
            );

            if (! $targetId || $targetId === $sourceId || ! $relatedProfile) {
                continue;
            }

            $targetHandle = $this->displayUsernameForInstagramProfile($relatedProfile);

            if ($relationship->list_type === 'followers') {
                $this->mergeTrackedRelationshipEdge(
                    $edges,
                    $targetId,
                    $sourceId,
                    'Followerliste',
                    sprintf('%s folgt %s laut gespeicherter Followerliste.', $targetHandle, $sourceHandle),
                );

                continue;
            }

            if ($relationship->list_type === 'following') {
                $this->mergeTrackedRelationshipEdge(
                    $edges,
                    $sourceId,
                    $targetId,
                    'Gefolgt-Liste',
                    sprintf('%s folgt %s laut gespeicherter Gefolgt-Liste.', $sourceHandle, $targetHandle),
                );
            }
        }
    }

    private function addTrackedRelationshipListEdges(
        ?TrackedPerson $primaryPerson,
        Collection $peopleByInstagram,
        Collection $nodesByInstagram,
        array &$nodes,
        array &$edges,
    ): void {
        if (! $primaryPerson) {
            return;
        }

        $sourceId = 'person-'.$primaryPerson->id;

        foreach ($this->loadSnapshotRelationshipItems($primaryPerson->latestInstagramSnapshot, 'followersList') as $item) {
            $targetNode = $this->ensureRelationshipItemNode($nodes, $nodesByInstagram, $peopleByInstagram, $item);

            if (! $targetNode || $targetNode['id'] === $sourceId) {
                continue;
            }

            $this->mergeTrackedRelationshipEdge(
                $edges,
                $targetNode['id'],
                $sourceId,
                'Followerliste',
                sprintf(
                    '@%s wurde in der Followerliste von %s gefunden.',
                    $this->displayUsernameForNode($targetNode),
                    $this->displayUsernameForPerson($primaryPerson),
                ),
            );
        }

        foreach ($this->loadSnapshotRelationshipItems($primaryPerson->latestInstagramSnapshot, 'followingList') as $item) {
            $targetNode = $this->ensureRelationshipItemNode($nodes, $nodesByInstagram, $peopleByInstagram, $item);

            if (! $targetNode || $targetNode['id'] === $sourceId) {
                continue;
            }

            $this->mergeTrackedRelationshipEdge(
                $edges,
                $sourceId,
                $targetNode['id'],
                'Gefolgt-Liste',
                sprintf(
                    '@%s wurde in der Gefolgt-Liste von %s gefunden.',
                    $this->displayUsernameForNode($targetNode),
                    $this->displayUsernameForPerson($primaryPerson),
                ),
            );
        }
    }

    private function addObservedTrackedPersonConnectionsToPrimary(
        Collection $trackedPeople,
        ?TrackedPerson $primaryPerson,
        Collection $nodesByInstagram,
        array &$nodes,
        array &$edges,
    ): void {
        if (! $primaryPerson) {
            return;
        }

        $primaryUsername = $this->normalizeUsername($primaryPerson->instagram_username);

        if ($primaryUsername === '') {
            return;
        }

        $primaryId = 'person-'.$primaryPerson->id;

        foreach ($trackedPeople as $person) {
            if ((int) $person->id === (int) $primaryPerson->id || ! filled($person->instagram_username)) {
                continue;
            }

            $observedId = null;
            $ensureObserved = function () use (&$observedId, &$nodesByInstagram, &$nodes, $person): string {
                if ($observedId) {
                    return $observedId;
                }

                $observedId = $this->ensureTrackedPersonNode($nodes, $person);
                $username = $this->normalizeUsername($person->instagram_username);

                if ($username !== '') {
                    $nodesByInstagram->put($username, $nodes[$observedId]);
                }

                return $observedId;
            };

            foreach ($person->currentInstagramProfile?->sourceRelationships ?? collect() as $relationship) {
                if (! $this->isActiveListRelationship($relationship)) {
                    continue;
                }

                $relatedUsername = $this->normalizeUsername($relationship->relatedInstagramProfile?->username);

                if ($relatedUsername !== $primaryUsername) {
                    continue;
                }

                $observedNodeId = $ensureObserved();

                if ($relationship->list_type === 'followers') {
                    $this->mergeTrackedRelationshipEdge(
                        $edges,
                        $primaryId,
                        $observedNodeId,
                        'Followerliste',
                        sprintf(
                            '%s folgt %s laut gespeicherter Followerliste.',
                            $this->displayUsernameForPerson($primaryPerson),
                            $this->displayUsernameForPerson($person),
                        ),
                    );

                    continue;
                }

                if ($relationship->list_type === 'following') {
                    $this->mergeTrackedRelationshipEdge(
                        $edges,
                        $observedNodeId,
                        $primaryId,
                        'Gefolgt-Liste',
                        sprintf(
                            '%s folgt %s laut gespeicherter Gefolgt-Liste.',
                            $this->displayUsernameForPerson($person),
                            $this->displayUsernameForPerson($primaryPerson),
                        ),
                    );
                }
            }

            foreach ($this->loadSnapshotRelationshipItems($person->latestInstagramSnapshot, 'followersList') as $item) {
                if (! $this->relationshipItemMatchesUsername($item, $primaryUsername)) {
                    continue;
                }

                $this->mergeTrackedRelationshipEdge(
                    $edges,
                    $primaryId,
                    $ensureObserved(),
                    'Followerliste',
                    sprintf(
                        '%s wurde in der Followerliste von %s gefunden.',
                        $this->displayUsernameForPerson($primaryPerson),
                        $this->displayUsernameForPerson($person),
                    ),
                );
            }

            foreach ($this->loadSnapshotRelationshipItems($person->latestInstagramSnapshot, 'followingList') as $item) {
                if (! $this->relationshipItemMatchesUsername($item, $primaryUsername)) {
                    continue;
                }

                $this->mergeTrackedRelationshipEdge(
                    $edges,
                    $ensureObserved(),
                    $primaryId,
                    'Gefolgt-Liste',
                    sprintf(
                        '%s wurde in der Gefolgt-Liste von %s gefunden.',
                        $this->displayUsernameForPerson($primaryPerson),
                        $this->displayUsernameForPerson($person),
                    ),
                );
            }
        }
    }

    private function relationshipItemMatchesUsername(mixed $item, string $username): bool
    {
        if (! is_array($item) || ! filled($item['username'] ?? null)) {
            return false;
        }

        return $this->normalizeUsername((string) $item['username']) === $username;
    }

    private function indexGraphNodesByInstagramUsername(array $nodes): Collection
    {
        $indexed = collect();
        $prioritizedNodes = collect($nodes)
            ->map(function (array $node): array {
                $username = $this->normalizeUsername($node['username'] ?? $node['handle'] ?? '');

                return [
                    ...$node,
                    'username' => $username,
                ];
            })
            ->filter(fn (array $node): bool => filled($node['username']))
            ->sortByDesc(fn (array $node): int => match ($node['type'] ?? null) {
                'person' => 3,
                'profile' => 2,
                'candidate' => 1,
                default => 0,
            });

        foreach ($prioritizedNodes as $node) {
            if (! $indexed->has($node['username'])) {
                $indexed->put($node['username'], $node);
            }
        }

        return $indexed;
    }

    private function ensureInstagramProfileNode(
        array &$nodes,
        Collection $nodesByInstagram,
        Collection $peopleByInstagram,
        ?InstagramProfile $profile,
        string $role,
    ): ?string {
        if (! $profile || ! filled($profile->username)) {
            return null;
        }

        $username = $this->normalizeUsername($profile->username);

        if ($username === '') {
            return null;
        }

        $existing = $nodesByInstagram->get($username);

        if ($existing) {
            $this->mergeInstagramProfileNodeDetails($nodes, $nodesByInstagram, $existing, $profile, $role);

            return $existing['id'];
        }

        $person = $peopleByInstagram->get($username);

        if ($person) {
            $nodeId = $this->ensureTrackedPersonNode($nodes, $person);
            $nodesByInstagram->put($username, $nodes[$nodeId]);

            return $nodeId;
        }

        $imageUrl = $this->profileImageUrlForInstagramProfile($profile);
        $node = [
            'id' => 'profile-instagram-'.$username,
            'type' => 'profile',
            'label' => $profile->display_name ?: $profile->full_name ?: $profile->display_handle,
            'handle' => $profile->display_handle,
            'username' => $username,
            'platform' => 'instagram',
            'imageUrl' => $imageUrl,
            'hasImage' => filled($imageUrl),
            'isPrimary' => false,
            'role' => $role,
            'status' => $this->profileStatusForInstagramProfile($profile),
            'detail' => $this->profileDetailForInstagramProfile($profile),
            'isKnownProfile' => false,
        ];

        $nodes[$node['id']] = $node;
        $nodesByInstagram->put($username, $node);

        return $node['id'];
    }

    private function ensureRelationshipItemNode(
        array &$nodes,
        Collection $nodesByInstagram,
        Collection $peopleByInstagram,
        mixed $item,
    ): ?array
    {
        if (! is_array($item) || ! filled($item['username'] ?? null)) {
            return null;
        }

        $username = $this->normalizeUsername((string) $item['username']);

        if ($username === '') {
            return null;
        }

        $existing = $nodesByInstagram->get($username);

        if ($existing) {
            $this->mergeRelationshipItemNodeImage($nodes, $nodesByInstagram, $existing, $item);

            return $nodes[$existing['id'] ?? null] ?? $existing;
        }

        $person = $peopleByInstagram->get($username);

        if ($person) {
            $nodeId = $this->ensureTrackedPersonNode($nodes, $person);
            $node = $nodes[$nodeId] ?? null;
            $this->mergeRelationshipItemNodeImage($nodes, $nodesByInstagram, $node ?? [], $item);
            $node = $nodes[$nodeId] ?? $node;
            $nodesByInstagram->put($username, $node);

            return $node;
        }

        $displayName = $item['displayName'] ?? $item['fullName'] ?? $item['name'] ?? null;
        $profileUrl = $item['profileUrl'] ?? $item['url'] ?? 'https://www.instagram.com/'.$username.'/';
        $imageUrl = $this->profileImageUrlForRelationshipItem($item);
        $node = [
            'id' => 'profile-instagram-'.$username,
            'type' => 'profile',
            'label' => filled($displayName) ? (string) $displayName : '@'.$username,
            'handle' => '@'.$username,
            'username' => $username,
            'platform' => 'instagram',
            'imageUrl' => $imageUrl,
            'hasImage' => filled($imageUrl),
            'isPrimary' => false,
            'role' => 'Listeneintrag',
            'status' => 'listed',
            'detail' => 'Aus einer gespeicherten Instagram-Liste. '.($profileUrl ? 'Profil: '.$profileUrl : ''),
            'isKnownProfile' => false,
        ];

        $nodes[$node['id']] = $node;
        $nodesByInstagram->put($username, $node);

        return $node;
    }

    private function mergeRelationshipItemNodeImage(
        array &$nodes,
        Collection $nodesByInstagram,
        array $existing,
        mixed $item,
    ): void {
        $id = $existing['id'] ?? null;

        if (! $id || ! isset($nodes[$id]) || (bool) ($nodes[$id]['hasImage'] ?? false)) {
            return;
        }

        $imageUrl = $this->profileImageUrlForRelationshipItem($item);

        if (! filled($imageUrl)) {
            return;
        }

        $nodes[$id]['imageUrl'] = $imageUrl;
        $nodes[$id]['hasImage'] = true;

        $username = $this->normalizeUsername($nodes[$id]['username'] ?? $existing['username'] ?? '');

        if ($username !== '') {
            $nodesByInstagram->put($username, $nodes[$id]);
        }
    }

    private function mergeNodeDetail(?string $current, ?string $additional): ?string
    {
        $parts = collect([$current, $additional])
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values();

        return $parts->isNotEmpty() ? $parts->implode(' | ') : null;
    }

    private function profileImageUrlForRelationshipItem(mixed $item): ?string
    {
        return null;
    }

    private function mergeInstagramProfileNodeDetails(
        array &$nodes,
        Collection $nodesByInstagram,
        array $existing,
        InstagramProfile $profile,
        string $role,
    ): void {
        $id = $existing['id'] ?? null;

        if (! $id || ! isset($nodes[$id])) {
            return;
        }

        $imageUrl = $this->profileImageUrlForInstagramProfile($profile);

        if (! (bool) ($nodes[$id]['hasImage'] ?? false) && filled($imageUrl)) {
            $nodes[$id]['imageUrl'] = $imageUrl;
            $nodes[$id]['hasImage'] = true;
        }

        if (($nodes[$id]['role'] ?? '') === 'Listeneintrag' && $role !== 'Listeneintrag') {
            $nodes[$id]['role'] = $role;
        }

        if (blank($nodes[$id]['detail'] ?? null)) {
            $nodes[$id]['detail'] = $this->profileDetailForInstagramProfile($profile);
        }

        if (($nodes[$id]['status'] ?? 'unknown') === 'unknown') {
            $nodes[$id]['status'] = $this->profileStatusForInstagramProfile($profile);
        }

        $username = $this->normalizeUsername($nodes[$id]['username'] ?? $profile->username);

        if ($username !== '') {
            $nodesByInstagram->put($username, $nodes[$id]);
        }
    }

    private function displayUsernameForNode(array $node): string
    {
        return $this->normalizeUsername($node['username'] ?? $node['handle'] ?? $node['label'] ?? '');
    }

    private function displayUsernameForPerson(TrackedPerson $person): string
    {
        $username = $this->normalizeUsername($person->instagram_username);

        return $username !== '' ? '@'.$username : $person->display_name;
    }

    private function displayUsernameForInstagramProfile(InstagramProfile $profile): string
    {
        $username = $this->normalizeUsername($profile->username);

        return $username !== '' ? '@'.$username : $profile->display_handle;
    }

    private function shouldShowStoredListProfile(?InstagramProfile $profile, bool $markedPublic): bool
    {
        if (! $profile) {
            return false;
        }

        return $markedPublic
            || $this->instagramProfileIsPublic($profile)
            || $profile->sourceRelationships->isNotEmpty();
    }

    private function isActiveListRelationship(InstagramProfileRelationship $relationship): bool
    {
        return $relationship->status === 'active'
            && in_array($relationship->list_type, ['followers', 'following'], true)
            && $relationship->removed_at === null;
    }

    private function trackedPersonInstagramProfileIsPublic(TrackedPerson $person): bool
    {
        $rawPayload = is_array($person->latestInstagramSnapshot?->raw_payload)
            ? $person->latestInstagramSnapshot->raw_payload
            : [];

        return data_get($rawPayload, 'extractedProfile.profileVisibility') === 'public'
            || data_get($rawPayload, 'extractedProfile.isPrivate') === false;
    }

    private function instagramProfileIsPublic(?InstagramProfile $profile): bool
    {
        if (! $profile) {
            return false;
        }

        return $profile->profile_visibility === 'public' || $profile->is_private === false;
    }

    private function profileStatusForInstagramProfile(InstagramProfile $profile): string
    {
        if ($this->instagramProfileIsPublic($profile)) {
            return 'public';
        }

        if ($profile->is_private === true || $profile->profile_visibility === 'private') {
            return 'private';
        }

        return $profile->profile_visibility ?: 'unknown';
    }

    private function profileDetailForInstagramProfile(InstagramProfile $profile): ?string
    {
        $parts = collect([
            $profile->followers_count !== null ? 'Follower: '.number_format($profile->followers_count, 0, ',', '.') : null,
            $profile->following_count !== null ? 'Gefolgt: '.number_format($profile->following_count, 0, ',', '.') : null,
            $profile->last_scanned_at ? 'Zuletzt gescannt: '.$profile->last_scanned_at->timezone(config('app.timezone'))->format('d.m.Y H:i') : null,
        ])->filter();

        return $parts->isNotEmpty() ? $parts->implode(' | ') : null;
    }

    private function mergeTrackedRelationshipEdge(array &$edges, string $from, string $to, string $sourceLabel, string $detail): void
    {
        $edgeId = 'tracked-list-'.$from.'-'.$to;

        if (! isset($edges[$edgeId])) {
            $edges[$edgeId] = [
                'id' => $edgeId,
                'from' => $from,
                'to' => $to,
                'type' => 'tracked-list',
                'label' => $sourceLabel,
                'sourceHandle' => $sourceLabel,
                'evidence' => [$detail],
            ];

            return;
        }

        $sourceLabels = collect(explode(' + ', (string) ($edges[$edgeId]['label'] ?? '')))
            ->filter()
            ->push($sourceLabel)
            ->unique()
            ->values()
            ->all();

        $edges[$edgeId]['label'] = implode(' + ', $sourceLabels);
        $edges[$edgeId]['sourceHandle'] = $edges[$edgeId]['label'];
        $edges[$edgeId]['evidence'] = collect($edges[$edgeId]['evidence'] ?? [])
            ->push($detail)
            ->unique()
            ->values()
            ->all();
    }

    private function profileImageUrlForPerson(TrackedPerson $person): ?string
    {
        if (filled($person->instagram_profile_image_path)) {
            return Storage::disk('public')->url($person->instagram_profile_image_path);
        }

        if (filled($person->latestInstagramSnapshot?->profile_image_storage_url)) {
            return $person->latestInstagramSnapshot->profile_image_storage_url;
        }

        return null;
    }

    private function profileImageUrlForInstagramProfile(?InstagramProfile $profile): ?string
    {
        if (! $profile) {
            return null;
        }

        if (filled($profile->profile_image_path)) {
            return Storage::disk('public')->url($profile->profile_image_path);
        }

        return null;
    }

    private function loadSnapshotRelationshipItems(?TrackedPersonInstagramSnapshot $snapshot, string $payloadKey): array
    {
        $rawPayload = is_array($snapshot?->raw_payload) ? $snapshot->raw_payload : [];
        $relationshipList = data_get($rawPayload, 'extractedProfile.'.$payloadKey, []);

        if (! is_array($relationshipList) || $relationshipList === []) {
            return [];
        }

        $itemsPath = data_get($relationshipList, 'itemsPath');

        if (is_string($itemsPath) && $itemsPath !== '' && Storage::disk('public')->exists($itemsPath)) {
            try {
                $decoded = json_decode(Storage::disk('public')->get($itemsPath), true, flags: JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    return $this->loadActiveRelationshipItemsFromPayload($decoded);
                }
            } catch (\Throwable) {
                return [];
            }
        }

        return $this->loadActiveRelationshipItemsFromPayload($relationshipList);
    }

    private function loadActiveRelationshipItemsFromPayload(array $payload): array
    {
        foreach (['activeItems', 'observedItems', 'observedPreview'] as $key) {
            if (array_key_exists($key, $payload)) {
                $items = data_get($payload, $key, []);

                return is_array($items) ? $this->filterActiveRelationshipItems($items) : [];
            }
        }

        if (! $this->hasHistoricalRelationshipMarkers($payload)) {
            foreach (['items', 'itemsPreview'] as $key) {
                if (! array_key_exists($key, $payload)) {
                    continue;
                }

                $items = data_get($payload, $key, []);

                return is_array($items) ? $this->filterActiveRelationshipItems($items) : [];
            }
        }

        return [];
    }

    private function hasHistoricalRelationshipMarkers(array $payload): bool
    {
        foreach ([
            'allKnownItems',
            'allKnownCount',
            'removedItems',
            'removedCount',
            'removedHistoryItems',
            'removedHistoryCount',
            'removedHistoryPreview',
            'currentlyRemovedItems',
            'currentlyRemovedCount',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function filterActiveRelationshipItems(array $items): array
    {
        return collect($items)
            ->filter(function ($item): bool {
                if (! is_array($item) || ! filled($item['username'] ?? null)) {
                    return false;
                }

                if (filled($item['removedAt'] ?? null)) {
                    return false;
                }

                $status = Str::lower((string) ($item['status'] ?? ''));

                return ! in_array($status, ['removed', 'deleted', 'inactive'], true);
            })
            ->values()
            ->all();
    }

    private function publicProfileEdges(string $sourceId, string $targetId, ?string $relationshipType): array
    {
        return match ($relationshipType) {
            'follows_target' => [[
                'id' => 'public-'.$targetId.'-'.$sourceId.'-follows',
                'from' => $targetId,
                'to' => $sourceId,
            ]],
            'followed_by_target' => [[
                'id' => 'public-'.$sourceId.'-'.$targetId.'-followed',
                'from' => $sourceId,
                'to' => $targetId,
            ]],
            'mutual' => [
                [
                    'id' => 'public-'.$sourceId.'-'.$targetId.'-mutual-a',
                    'from' => $sourceId,
                    'to' => $targetId,
                ],
                [
                    'id' => 'public-'.$targetId.'-'.$sourceId.'-mutual-b',
                    'from' => $targetId,
                    'to' => $sourceId,
                ],
            ],
            default => [[
                'id' => 'public-'.$sourceId.'-'.$targetId.'-connection',
                'from' => $sourceId,
                'to' => $targetId,
            ]],
        };
    }

    private function applyLayout(array $nodes, array $edges): array
    {
        $width = 1200;
        $height = 760;
        $centerX = $width / 2;
        $centerY = $height / 2;
        $positions = [];

        // Calculate connection count for each node to determine proximity
        $connectionCounts = $this->calculateConnectionCounts($nodes, $edges);

        // Find primary person (center)
        $primaryNode = collect($nodes)->firstWhere('isPrimary', true);
        if ($primaryNode) {
            $positions[$primaryNode['id']] = [
                'x' => $centerX,
                'y' => $centerY,
            ];
        }

        // Separate nodes by type
        $people = array_values(array_filter($nodes, fn (array $node): bool => $node['type'] === 'person' && !($node['isPrimary'] ?? false)));
        $profiles = array_values(array_filter($nodes, fn (array $node): bool => $node['type'] === 'profile'));
        $candidates = array_values(array_filter($nodes, fn (array $node): bool => $node['type'] === 'candidate'));

        // Sort profiles and other nodes by connection count (descending)
        usort($profiles, function (array $a, array $b) use ($connectionCounts) {
            return ($connectionCounts[$b['id']] ?? 0) - ($connectionCounts[$a['id']] ?? 0);
        });
        usort($candidates, function (array $a, array $b) use ($connectionCounts) {
            return ($connectionCounts[$b['id']] ?? 0) - ($connectionCounts[$a['id']] ?? 0);
        });

        // Place people in first ring (closely connected)
        foreach ($people as $index => $node) {
            $angle = $this->angle($index, max(1, count($people)), -90);
            $radius = count($people) <= 1 ? 0 : min(260, 130 + count($people) * 16);
            $positions[$node['id']] = [
                'x' => $centerX + cos($angle) * $radius,
                'y' => $centerY + sin($angle) * $radius,
            ];
        }

        // Place profiles with adaptive radius based on connection density
        foreach ($profiles as $index => $node) {
            $connectionCount = $connectionCounts[$node['id']] ?? 0;
            // Nodes with more connections get placed closer
            $angle = $this->angle($index, max(1, count($profiles)), -75);
            $max_radius = min(340, 210 + count($profiles) * 4);
            // Adaptive radius: more connections = smaller radius
            $maxConnections = max(1, max(array_values($connectionCounts) ?: [1]));
            $radius = $max_radius * (1 - min(0.6, $connectionCount / $maxConnections));
            $positions[$node['id']] = [
                'x' => $centerX + cos($angle) * $radius,
                'y' => $centerY + sin($angle) * $radius,
            ];
        }

        // Place candidates in outer ring
        foreach ($candidates as $index => $node) {
            $angle = $this->angle($index, max(1, count($candidates)), -120);
            $radius = min(380, 300 + count($candidates) * 3);
            $positions[$node['id']] = [
                'x' => $centerX + cos($angle) * $radius,
                'y' => $centerY + sin($angle) * $radius,
            ];
        }

        foreach ($nodes as $index => $node) {
            $position = $positions[$node['id']] ?? ['x' => $centerX, 'y' => $centerY];
            $nodes[$index] = [
                ...$node,
                'x' => round($position['x'], 1),
                'y' => round($position['y'], 1),
            ];
        }

        $nodeLookup = collect($nodes)->keyBy('id');
        $edges = array_values(array_filter(array_map(function (array $edge) use ($nodeLookup): ?array {
            $from = $nodeLookup->get($edge['from']);
            $to = $nodeLookup->get($edge['to']);

            if (! $from || ! $to) {
                return null;
            }

            return [
                ...$edge,
                'x1' => $from['x'],
                'y1' => $from['y'],
                'x2' => $to['x'],
                'y2' => $to['y'],
                'mx' => round(($from['x'] + $to['x']) / 2, 1),
                'my' => round(($from['y'] + $to['y']) / 2, 1),
            ];
        }, $edges)));

        return [
            'width' => $width,
            'height' => $height,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function calculateConnectionCounts(array $nodes, array $edges): array
    {
        $counts = [];
        foreach ($nodes as $node) {
            $counts[$node['id']] = 0;
        }

        foreach ($edges as $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;

            if ($from && isset($counts[$from])) {
                $counts[$from]++;
            }
            if ($to && isset($counts[$to])) {
                $counts[$to]++;
            }
        }

        return $counts;
    }

    private function angle(int $index, int $count, float $offsetDegrees = 0): float
    {
        return deg2rad($offsetDegrees + (($index / max(1, $count)) * 360));
    }

    private function addTrackedPersonProfileRelationships(
        Collection $trackedPeople,
        Collection $nodesByInstagram,
        array &$nodes,
        array &$edges,
    ): void {
        // Add inter-profile relationships from tracked people's Instagram profiles
        $processedRelationships = [];

        foreach ($trackedPeople as $person) {
            if (! filled($person->instagram_username)) {
                continue;
            }

            $sourceProfile = $person->currentInstagramProfile;
            if (! $sourceProfile) {
                continue;
            }

            // Process Instagram profile relationships (followers/following lists)
            foreach ($sourceProfile->sourceRelationships as $relationship) {
                if (! $this->isActiveListRelationship($relationship)) {
                    continue;
                }

                $relatedProfile = $relationship->relatedInstagramProfile;
                if (! $relatedProfile || ! filled($relatedProfile->username)) {
                    continue;
                }

                $relatedUsername = $this->normalizeUsername($relatedProfile->username);
                $existingNode = $nodesByInstagram->get($relatedUsername);

                // Only create edge if both nodes exist in the graph
                if (! $existingNode) {
                    continue;
                }

                $sourceId = 'person-'.$person->id;
                $targetId = $existingNode['id'];

                // Avoid duplicate edges
                $relationshipKey = min($sourceId, $targetId) . '|' . max($sourceId, $targetId);
                if (isset($processedRelationships[$relationshipKey])) {
                    continue;
                }

                $processedRelationships[$relationshipKey] = true;

                // Determine direction based on list type
                if ($relationship->list_type === 'followers') {
                    $from = $targetId;
                    $to = $sourceId;
                    $direction = 'folgt';
                } else {
                    $from = $sourceId;
                    $to = $targetId;
                    $direction = 'folgt';
                }

                $edgeId = 'profile-rel-'.$from.'-'.$to;

                // Merge or create edge
                if (! isset($edges[$edgeId])) {
                    $edges[$edgeId] = [
                        'id' => $edgeId,
                        'from' => $from,
                        'to' => $to,
                        'type' => 'tracked-profile-rel',
                        'label' => $direction,
                        'sourceHandle' => null,
                        'evidence' => [],
                    ];
                }
            }
        }
    }

    private function normalizeUsername(?string $username): string
    {
        return Str::lower(ltrim(trim((string) $username), '@'));
    }

    /**
     * Scan a profile in the background and fetch its followers/following.
     * Dispatches a background job to avoid blocking the UI.
     */
    public function scanProfile(string $nodeId): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        [$profile, $username] = $this->resolveInstagramProfileFromNodeId($nodeId);

        if (! $profile && filled($username)) {
            $profile = app(InstagramProfileRelationshipStore::class)->ensureProfile($username);
        }

        if (! $profile) {
            $this->dispatch('notification', type: 'error', message: 'Profil konnte nicht gefunden werden');
            return;
        }

        try {
            $trackedPerson = $this->getPrimaryTrackedPerson($user);

            if (! $trackedPerson) {
                $this->dispatch('notification', type: 'error', message: 'Keine Hauptperson ausgewaehlt');
                return;
            }

            $publicProfile = $this->firstOrCreateKnownProfile($trackedPerson, $profile);

            \App\Jobs\ScanInstagramProfileJob::dispatch($trackedPerson->id, $publicProfile->id, (int) $user->id);
            $this->dispatch('notification', type: 'success', message: 'Profil-Scan wurde als Hintergrund-Job gestartet');
        } catch (\Throwable $e) {
            $this->dispatch('notification', type: 'error', message: 'Fehler beim Starten des Scans: '.$e->getMessage());
        }
    }

    /**
     * Add a profile as a known profile to the current user.
     * Creates a new public profile link if it doesn't already exist.
     */
    public function addProfileAsKnown(string $nodeId): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        [$profile, $username] = $this->resolveInstagramProfileFromNodeId($nodeId);

        if (! $profile && filled($username)) {
            $profile = app(InstagramProfileRelationshipStore::class)->ensureProfile($username);
        }
        if (! $profile || ! filled($profile->username)) {
            $this->dispatch('notification', type: 'error', message: 'Profil konnte nicht hinzugefügt werden');
            return;
        }

        try {
            $trackedPerson = $this->getPrimaryTrackedPerson($user);
            if (! $trackedPerson) {
                $this->dispatch('notification', type: 'error', message: 'Keine Hauptperson ausgewählt');
                return;
            }

            // Check if profile already exists
            $existingPublicProfile = $trackedPerson->publicProfiles()
                ->where('platform', 'instagram')
                ->where('username', $this->normalizeUsername($profile->username))
                ->exists();

            if ($existingPublicProfile) {
                $this->dispatch('notification', type: 'warning', message: 'Profil ist bereits als bekannt gespeichert');
                return;
            }

            $this->firstOrCreateKnownProfile($trackedPerson, $profile);

            $this->forgetGraphCache((int) $user->id);

            // Reset graph to refresh UI
            $this->graphToken = null;
            $this->prepareGraph();
            $this->dispatch('notification', type: 'success', message: 'Profil wurde als bekannt gespeichert');

        } catch (\Throwable $e) {
            $this->dispatch('notification', type: 'error', message: 'Fehler beim Speichern: '.$e->getMessage());
        }
    }

    /**
     * @return array{0: ?InstagramProfile, 1: ?string}
     */
    private function resolveInstagramProfileFromNodeId(string $nodeId): array
    {
        $username = null;

        if (str_starts_with($nodeId, 'profile-instagram-')) {
            $username = substr($nodeId, strlen('profile-instagram-'));
        } elseif (str_starts_with($nodeId, 'candidate-')) {
            $username = substr($nodeId, strlen('candidate-'));
        }

        if (! filled($username)) {
            return [null, null];
        }

        $username = $this->normalizeUsername($username);

        return [InstagramProfile::where('username', $username)->first(), $username];
    }

    private function firstOrCreateKnownProfile(TrackedPerson $trackedPerson, InstagramProfile $profile)
    {
        $username = $this->normalizeUsername($profile->username);

        $publicProfile = $trackedPerson->publicProfiles()->firstOrCreate(
            [
                'platform' => 'instagram',
                'username' => $username,
            ],
            [
                'user_id' => $trackedPerson->user_id,
                'instagram_profile_id' => $profile->id,
                'display_name' => $profile->display_name ?: $profile->full_name,
                'relationship_type' => 'public_connection',
                'profile_url' => 'https://www.instagram.com/'.$username.'/',
                'is_public' => $this->instagramProfileIsPublic($profile),
            ],
        );

        if ((int) $publicProfile->instagram_profile_id !== (int) $profile->id) {
            $publicProfile->forceFill(['instagram_profile_id' => $profile->id])->save();
        }

        app(InstagramProfileRelationshipStore::class)->syncPublicProfile($publicProfile);

        return $publicProfile;
    }

    private function forgetGraphCache(int $userId): void
    {
        $token = Cache::get(self::graphHashCacheKey($userId, contextTrackedPersonId: $this->contextTrackedPersonId));

        if (is_string($token) && $token !== '') {
            Cache::forget(self::graphCacheKey($userId, $token));
            Cache::forget(self::graphHashCacheKey($userId, $token, $this->contextTrackedPersonId));
        }

        Cache::forget(self::graphHashCacheKey($userId, contextTrackedPersonId: $this->contextTrackedPersonId));
    }

    /**
     * Get the primary tracked person for a user.
     */
    private function getPrimaryTrackedPerson(User $user): ?TrackedPerson
    {
        return $user->trackedPeople()
            ->orderByDesc('is_primary')
            ->first();
    }
}
