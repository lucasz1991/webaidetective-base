<?php

namespace App\Livewire\User;

use App\Jobs\ScanInstagramProfileJob;
use App\Models\InstagramProfile;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Models\User;
use App\Services\TrackedPeople\InstagramProfileRelationshipStore;
use App\Services\TrackedPeople\InstagramProfileScanService;
use App\Services\TrackedPeople\TrackedPersonQuotaService;
use App\Support\PublicAssetUrl;
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

    private const MAX_GRAPH_NODES = 250;

    private const MAX_CONTACT_IMAGES = 50;

    private const MAX_SYSTEM_RELATIONSHIPS = 1500;

    public ?int $primaryTrackedPersonId = null;

    public ?int $contextTrackedPersonId = null;

    public bool $embedded = false;

    public string $mapId = '';

    public ?string $graphToken = null;

    public bool $showProfilePreviewModal = false;

    public ?string $previewNodeId = null;

    public array $profilePreview = [];

    public array $graphStats = [
        'people' => 0,
        'nodes' => 0,
        'edges' => 0,
        'inferred' => 0,
        'trackedList' => 0,
        'systemWide' => 0,
    ];

    public array $cacheDebug = [];

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

    public static function graphMetaCacheKey(int $userId, ?int $contextTrackedPersonId = null): string
    {
        $scope = $contextTrackedPersonId ? ':person-'.$contextTrackedPersonId : ':global';

        return 'network-map-meta:'.$userId.$scope;
    }

    public static function forgetGraphCacheForUser(int $userId, ?int $contextTrackedPersonId = null): void
    {
        $meta = Cache::get(self::graphMetaCacheKey($userId, $contextTrackedPersonId));
        $token = is_array($meta) ? ($meta['token'] ?? null) : null;

        if (is_string($token) && $token !== '') {
            Cache::forget(self::graphCacheKey($userId, $token));
        }

        Cache::forget(self::graphMetaCacheKey($userId, $contextTrackedPersonId));
        Cache::forget(self::graphHashCacheKey($userId, contextTrackedPersonId: $contextTrackedPersonId));
    }

    public function mount(?int $trackedPersonId = null, bool $embedded = false): void
    {
        $this->contextTrackedPersonId = $trackedPersonId;
        $this->embedded = $embedded;
        $this->mapId = 'network-map-'.Str::uuid();
        $this->cacheDebug = [
            'status' => 'idle',
            'scope' => $this->contextTrackedPersonId ? 'person-'.$this->contextTrackedPersonId : 'global',
        ];
    }

    private function generateDataHash(Collection $trackedPeople): string
    {
        $systemRelationshipState = InstagramProfileRelationship::query()
            ->where('status', 'active')
            ->whereIn('list_type', ['followers', 'following'])
            ->whereNull('removed_at')
            ->selectRaw('COUNT(*) as relationship_count, MAX(updated_at) as latest_updated_at')
            ->first();
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

        $data['graph_version'] = 8;
        $data['graph_node_limit'] = self::MAX_GRAPH_NODES;
        $data['contact_image_limit'] = self::MAX_CONTACT_IMAGES;
        $data['context_tracked_person_id'] = $this->contextTrackedPersonId;
        $data['system_relationships'] = [
            'count' => (int) ($systemRelationshipState?->relationship_count ?? 0),
            'updated_at' => $systemRelationshipState?->latest_updated_at,
            'profiles_updated_at' => InstagramProfile::query()->max('updated_at'),
        ];

        // Also include primary person flag
        $data['primary_person_id'] = $this->primaryTrackedPersonId;

        return hash('sha256', json_encode($data));
    }

    public function setPrimaryTrackedPerson($trackedPersonId): void
    {
        if ($this->embedded) {
            return;
        }

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
            $this->cacheDebug = [
                'status' => 'no-user',
                'scope' => $this->contextTrackedPersonId ? 'person-'.$this->contextTrackedPersonId : 'global',
            ];

            return;
        }

        $trackedPeople = $this->loadTrackedPeopleForGraph($user);
        $this->primaryTrackedPersonId = $this->resolvePrimaryTrackedPersonId($trackedPeople);

        if ($trackedPeople->isEmpty()) {
            $this->graphToken = null;
            $this->graphStats = $this->emptyGraphStats();
            $this->cacheDebug = [
                'status' => 'empty',
                'scope' => $this->contextTrackedPersonId ? 'person-'.$this->contextTrackedPersonId : 'global',
                'tracked_people' => 0,
            ];
            $this->dispatch('network-map-empty', mapId: $this->mapId, stats: $this->graphStats);

            return;
        }

        // Generate data hash for cache validation
        $dataHash = $this->generateDataHash($trackedPeople);
        $metaCacheKey = self::graphMetaCacheKey((int) $user->id, $this->contextTrackedPersonId);
        $graphHashPointerKey = self::graphHashCacheKey((int) $user->id, contextTrackedPersonId: $this->contextTrackedPersonId);
        $cachedMeta = Cache::get($metaCacheKey);
        $cachedToken = is_array($cachedMeta) ? ($cachedMeta['token'] ?? null) : null;
        $cachedHash = is_array($cachedMeta) ? ($cachedMeta['hash'] ?? null) : null;
        $cachedData = is_string($cachedToken) && $cachedToken !== ''
            ? Cache::get(self::graphCacheKey((int) $user->id, $cachedToken))
            : null;

        if (is_string($cachedToken) && $cachedToken !== '' && $cachedHash === $dataHash && is_array($cachedData)) {
            $this->graphToken = $cachedToken;
            $this->graphStats = $cachedData['stats'] ?? $this->graphStats;
            $this->cacheDebug = [
                'status' => 'hit',
                'scope' => $this->contextTrackedPersonId ? 'person-'.$this->contextTrackedPersonId : 'global',
                'token' => $cachedToken,
                'data_hash' => $dataHash,
                'cached_hash' => $cachedHash,
                'chunk_count' => count($cachedData['chunks'] ?? []),
                'meta_cache_key' => $metaCacheKey,
                'pointer_cache_key' => $graphHashPointerKey,
                'graph_cache_key' => self::graphCacheKey((int) $user->id, $cachedToken),
                'tracked_people' => $trackedPeople->count(),
                'generated_at' => $cachedMeta['generated_at'] ?? null,
            ];
            $this->dispatch(
                'network-map-graph-prepared',
                mapId: $this->mapId,
                token: $cachedToken,
                dataHash: $dataHash,
                chunkCount: count($cachedData['chunks'] ?? []),
                chunkUrl: route('network.graph-chunk', ['token' => $cachedToken, 'chunk' => '__CHUNK__']),
                stats: $this->graphStats,
            );

            return;
        }

        if (is_string($cachedToken) && $cachedToken !== '') {
            Cache::forget(self::graphCacheKey((int) $user->id, $cachedToken));
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
            $metaCacheKey,
            [
                'token' => $token,
                'hash' => $dataHash,
                'generated_at' => now()->toDateTimeString(),
                'chunk_count' => count($chunks),
            ],
            now()->addMinutes(30),
        );

        // Keep the lightweight pointer key for quick manual inspection.
        Cache::put(
            $graphHashPointerKey,
            $token,
            now()->addMinutes(30),
        );

        $this->graphToken = $token;
        $this->graphStats = $stats;
        $this->cacheDebug = [
            'status' => $cachedMeta ? 'rebuilt' : 'miss',
            'scope' => $this->contextTrackedPersonId ? 'person-'.$this->contextTrackedPersonId : 'global',
            'token' => $token,
            'data_hash' => $dataHash,
            'cached_hash' => $cachedHash,
            'previous_token' => $cachedToken,
            'chunk_count' => count($chunks),
            'meta_cache_key' => $metaCacheKey,
            'pointer_cache_key' => $graphHashPointerKey,
            'graph_cache_key' => self::graphCacheKey((int) $user->id, $token),
            'tracked_people' => $trackedPeople->count(),
            'generated_at' => now()->toDateTimeString(),
        ];
        $this->dispatch(
            'network-map-graph-prepared',
            mapId: $this->mapId,
            token: $token,
            dataHash: $dataHash,
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
            'cacheDebug' => $this->cacheDebug,
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
            'systemWide' => 0,
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
            'systemWide' => $edges->where('systemWideEvidence', true)->count(),
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
                        'profileVisibility' => $linkedInstagramProfile
                            ? $this->profileStatusForInstagramProfile($linkedInstagramProfile)
                            : ($publicProfile->is_public ? 'public' : 'unknown'),
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
                        'profileVisibility' => $connection->candidateInstagramProfile
                            ? $this->profileStatusForInstagramProfile($connection->candidateInstagramProfile)
                            : 'unknown',
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
        [$nodes, $edges] = $this->limitGraphProfiles($nodes, $edges);
        $nodesByInstagram = $this->indexGraphNodesByInstagramUsername($nodes);
        $this->addSystemWideDirectProfileConnections($peopleByInstagram, $nodesByInstagram, $nodes, $edges);
        [$nodes, $edges] = $this->limitGraphProfiles($nodes, $edges);
        $nodes = $this->limitGraphImages($nodes, $edges);

        return $this->applyLayout(array_values($nodes), array_values($edges));
    }

    private function limitGraphProfiles(array $nodes, array $edges): array
    {
        if (count($nodes) <= self::MAX_GRAPH_NODES) {
            return [$nodes, $this->edgesForNodes($edges, array_keys($nodes))];
        }

        $ranking = $this->graphNodeRanking($nodes, $edges);
        $focusId = $ranking['focus_id'];
        $retainedIds = collect($nodes)
            ->reject(fn (array $node): bool => ($node['id'] ?? null) === $focusId)
            ->sort(function (array $left, array $right) use ($ranking): int {
                return $this->compareGraphNodes($left, $right, $ranking);
            })
            ->take(self::MAX_GRAPH_NODES - ($focusId ? 1 : 0))
            ->pluck('id')
            ->when($focusId, fn (Collection $ids) => $ids->prepend($focusId))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $retainedLookup = array_fill_keys($retainedIds, true);

        return [
            array_filter(
                $nodes,
                fn (array $node): bool => isset($retainedLookup[$node['id'] ?? '']),
            ),
            $this->edgesForNodes($edges, $retainedIds),
        ];
    }

    private function limitGraphImages(array $nodes, array $edges): array
    {
        $ranking = $this->graphNodeRanking($nodes, $edges);
        $focusId = $ranking['focus_id'];
        $imageNodeIds = collect($nodes)
            ->filter(fn (array $node): bool => ($node['id'] ?? null) !== $focusId && filled($node['imageUrl'] ?? null))
            ->sort(function (array $left, array $right) use ($ranking): int {
                return $this->compareGraphNodes($left, $right, $ranking);
            })
            ->take(self::MAX_CONTACT_IMAGES)
            ->pluck('id')
            ->when($focusId, fn (Collection $ids) => $ids->push($focusId))
            ->filter()
            ->unique()
            ->flip();

        foreach ($nodes as $id => $node) {
            if ($imageNodeIds->has($node['id'] ?? null) && filled($node['imageUrl'] ?? null)) {
                continue;
            }

            $nodes[$id]['imageUrl'] = null;
            $nodes[$id]['hasImage'] = false;
        }

        return $nodes;
    }

    private function graphNodeRanking(array $nodes, array $edges): array
    {
        $focusNode = collect($nodes)->first(fn (array $node): bool => (bool) ($node['isFocus'] ?? false))
            ?: collect($nodes)->first(fn (array $node): bool => (bool) ($node['isPrimary'] ?? false))
            ?: collect($nodes)->first(fn (array $node): bool => ($node['type'] ?? null) === 'person');
        $focusId = $focusNode['id'] ?? null;
        $adjacency = [];

        foreach ($nodes as $node) {
            if (filled($node['id'] ?? null)) {
                $adjacency[$node['id']] = [];
            }
        }

        foreach ($edges as $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;

            if (! isset($adjacency[$from], $adjacency[$to]) || $from === $to) {
                continue;
            }

            $adjacency[$from][$to] = true;
            $adjacency[$to][$from] = true;
        }

        $distances = [];

        if ($focusId && isset($adjacency[$focusId])) {
            $distances[$focusId] = 0;
            $queue = new \SplQueue;
            $queue->enqueue($focusId);

            while (! $queue->isEmpty()) {
                $currentId = $queue->dequeue();
                $nextDistance = $distances[$currentId] + 1;

                foreach (array_keys($adjacency[$currentId]) as $neighborId) {
                    if (isset($distances[$neighborId])) {
                        continue;
                    }

                    $distances[$neighborId] = $nextDistance;
                    $queue->enqueue($neighborId);
                }
            }
        }

        return [
            'focus_id' => $focusId,
            'distances' => $distances,
            'degrees' => array_map('count', $adjacency),
        ];
    }

    private function compareGraphNodes(array $left, array $right, array $ranking): int
    {
        $leftId = (string) ($left['id'] ?? '');
        $rightId = (string) ($right['id'] ?? '');
        $distances = $ranking['distances'];
        $degrees = $ranking['degrees'];
        $leftRank = [
            $distances[$leftId] ?? PHP_INT_MAX,
            ($left['isKnownProfile'] ?? false) ? 0 : 1,
            -($degrees[$leftId] ?? 0),
            Str::lower((string) ($left['label'] ?? $leftId)),
        ];
        $rightRank = [
            $distances[$rightId] ?? PHP_INT_MAX,
            ($right['isKnownProfile'] ?? false) ? 0 : 1,
            -($degrees[$rightId] ?? 0),
            Str::lower((string) ($right['label'] ?? $rightId)),
        ];

        return $leftRank <=> $rightRank;
    }

    private function edgesForNodes(array $edges, array $nodeIds): array
    {
        $nodeLookup = array_fill_keys($nodeIds, true);

        return array_filter($edges, static function (array $edge) use ($nodeLookup): bool {
            return isset($nodeLookup[$edge['from'] ?? ''], $nodeLookup[$edge['to'] ?? '']);
        });
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
            'isFocus' => $this->contextTrackedPersonId
                ? (int) $person->id === (int) $this->contextTrackedPersonId
                : (bool) $person->is_primary,
            'role' => $person->is_primary ? 'Hauptperson' : 'Beobachtete Person',
            'status' => $person->last_instagram_status_level ?: 'neutral',
            'profileVisibility' => $this->profileStatusForTrackedPerson($person),
            'detail' => $person->last_instagram_status_message ?: null,
            'isKnownProfile' => false,
            'detailUrl' => route('tracked-people.show', ['trackedPersonId' => $person->id]),
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

    private function addSystemWideDirectProfileConnections(
        Collection $peopleByInstagram,
        Collection $nodesByInstagram,
        array &$nodes,
        array &$edges,
    ): void {
        $usernames = collect($nodes)
            ->map(fn (array $node): string => $this->normalizeUsername($node['username'] ?? $node['handle'] ?? ''))
            ->filter()
            ->unique()
            ->values();

        if ($usernames->isEmpty()) {
            return;
        }

        $seedProfiles = InstagramProfile::query()
            ->whereIn('username', $usernames->all())
            ->get();
        $seedProfileIds = $seedProfiles->pluck('id')->filter()->values();

        if ($seedProfileIds->isEmpty()) {
            return;
        }

        $relationships = InstagramProfileRelationship::query()
            ->where('status', 'active')
            ->whereIn('list_type', ['followers', 'following'])
            ->whereNull('removed_at')
            ->where(function ($query) use ($seedProfileIds): void {
                $query
                    ->whereIn('source_instagram_profile_id', $seedProfileIds->all())
                    ->orWhereIn('related_instagram_profile_id', $seedProfileIds->all());
            })
            ->with([
                'sourceInstagramProfile',
                'relatedInstagramProfile',
                'firstSeenScan:id,user_id',
                'lastSeenScan:id,user_id',
            ])
            ->latest('last_seen_at')
            ->limit(self::MAX_SYSTEM_RELATIONSHIPS)
            ->get();

        if ($relationships->isEmpty()) {
            return;
        }

        $evidenceByRelationship = $this->systemRelationshipEvidence($relationships);

        foreach ($relationships as $relationship) {
            $sourceProfile = $relationship->sourceInstagramProfile;
            $relatedProfile = $relationship->relatedInstagramProfile;
            $sourceId = $this->ensureInstagramProfileNode(
                $nodes,
                $nodesByInstagram,
                $peopleByInstagram,
                $sourceProfile,
                'Systemverbindung',
            );
            $relatedId = $this->ensureInstagramProfileNode(
                $nodes,
                $nodesByInstagram,
                $peopleByInstagram,
                $relatedProfile,
                'Systemverbindung',
            );

            if (! $sourceId || ! $relatedId || $sourceId === $relatedId || ! $sourceProfile || ! $relatedProfile) {
                continue;
            }

            $evidence = $evidenceByRelationship->get($relationship->id, []);
            $metadata = [
                'systemWideEvidence' => true,
                'ownUserEvidence' => (bool) ($evidence['own'] ?? false),
                'otherUserEvidence' => (bool) ($evidence['other'] ?? false),
                'systemEvidenceScanCount' => (int) ($evidence['scan_count'] ?? 0),
                'systemEvidenceUserCount' => (int) ($evidence['user_count'] ?? 0),
            ];
            $evidenceSuffix = $this->systemRelationshipEvidenceDescription($evidence);

            if ($relationship->list_type === 'followers') {
                $this->mergeTrackedRelationshipEdge(
                    $edges,
                    $relatedId,
                    $sourceId,
                    'Followerliste',
                    sprintf(
                        '%s folgt %s laut systemweit gespeicherter Followerliste. %s',
                        $this->displayUsernameForInstagramProfile($relatedProfile),
                        $this->displayUsernameForInstagramProfile($sourceProfile),
                        $evidenceSuffix,
                    ),
                    $metadata,
                );

                continue;
            }

            $this->mergeTrackedRelationshipEdge(
                $edges,
                $sourceId,
                $relatedId,
                'Gefolgt-Liste',
                sprintf(
                    '%s folgt %s laut systemweit gespeicherter Gefolgt-Liste. %s',
                    $this->displayUsernameForInstagramProfile($sourceProfile),
                    $this->displayUsernameForInstagramProfile($relatedProfile),
                    $evidenceSuffix,
                ),
                $metadata,
            );
        }
    }

    private function systemRelationshipEvidence(Collection $relationships): Collection
    {
        $relationshipIds = $relationships->pluck('id')->filter()->values();
        $currentUserId = (int) Auth::id();

        if ($relationshipIds->isEmpty()) {
            return collect();
        }

        $evidence = DB::table('instagram_profile_list_scan_items as items')
            ->join('instagram_profile_list_scans as scans', 'scans.id', '=', 'items.list_scan_id')
            ->whereIn('items.relationship_id', $relationshipIds->all())
            ->whereNull('items.deleted_at')
            ->whereNull('scans.deleted_at')
            ->select('items.relationship_id')
            ->selectRaw('COUNT(DISTINCT items.list_scan_id) as scan_count')
            ->selectRaw('COUNT(DISTINCT scans.user_id) as user_count')
            ->selectRaw('MAX(CASE WHEN scans.user_id = ? THEN 1 ELSE 0 END) as own_evidence', [$currentUserId])
            ->selectRaw(
                'MAX(CASE WHEN scans.user_id IS NOT NULL AND scans.user_id <> ? THEN 1 ELSE 0 END) as other_evidence',
                [$currentUserId],
            )
            ->groupBy('items.relationship_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->relationship_id => [
                    'scan_count' => (int) $row->scan_count,
                    'user_count' => (int) $row->user_count,
                    'own' => (bool) $row->own_evidence,
                    'other' => (bool) $row->other_evidence,
                ],
            ]);

        foreach ($relationships as $relationship) {
            if ($evidence->has($relationship->id)) {
                continue;
            }

            $scanIds = collect([
                $relationship->firstSeenScan?->id,
                $relationship->lastSeenScan?->id,
            ])->filter()->unique()->values();
            $scanUserIds = collect([
                $relationship->firstSeenScan?->user_id,
                $relationship->lastSeenScan?->user_id,
            ])->filter()->unique()->values();

            $evidence->put($relationship->id, [
                'scan_count' => $scanIds->count(),
                'user_count' => $scanUserIds->count(),
                'own' => $scanUserIds->contains($currentUserId),
                'other' => $scanUserIds->contains(fn ($userId): bool => (int) $userId !== $currentUserId),
            ]);
        }

        return $evidence;
    }

    private function systemRelationshipEvidenceDescription(array $evidence): string
    {
        $scanCount = (int) ($evidence['scan_count'] ?? 0);
        $userCount = (int) ($evidence['user_count'] ?? 0);
        $own = (bool) ($evidence['own'] ?? false);
        $other = (bool) ($evidence['other'] ?? false);

        if ($own && $other) {
            return sprintf(
                'Durch %s aus %s Benutzerkonten bestaetigt, darunter eigene und weitere System-Scans.',
                trans_choice(':count Scan|:count Scans', $scanCount, ['count' => number_format($scanCount, 0, ',', '.')]),
                number_format($userCount, 0, ',', '.'),
            );
        }

        if ($other) {
            return sprintf(
                'Durch %s anderer Benutzer im System erkannt.',
                trans_choice(':count Scan|:count Scans', $scanCount, ['count' => number_format($scanCount, 0, ',', '.')]),
            );
        }

        if ($own) {
            return 'Durch eigene gespeicherte Scans bestaetigt.';
        }

        return 'Aus dem systemweiten Profilgraphen uebernommen.';
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

        $targetUsernames = $this->focusedObservedTargetUsernames($primaryPerson);

        if ($targetUsernames->isEmpty()) {
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

                if (! $targetUsernames->contains($relatedUsername)) {
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
                if (! $this->relationshipItemMatchesUsernames($item, $targetUsernames)) {
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
                if (! $this->relationshipItemMatchesUsernames($item, $targetUsernames)) {
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

    private function focusedObservedTargetUsernames(TrackedPerson $primaryPerson): Collection
    {
        $usernames = collect([$primaryPerson->instagram_username]);

        if ($this->contextTrackedPersonId) {
            $usernames = $usernames
                ->merge([$primaryPerson->currentInstagramProfile?->username])
                ->merge($primaryPerson->publicProfiles->pluck('username'))
                ->merge($primaryPerson->publicProfiles->pluck('instagramProfile.username'));
        }

        return $usernames
            ->map(fn (mixed $username): string => $this->normalizeUsername((string) $username))
            ->filter()
            ->unique()
            ->values();
    }

    private function relationshipItemMatchesUsername(mixed $item, string $username): bool
    {
        if (! is_array($item) || ! filled($item['username'] ?? null)) {
            return false;
        }

        return $this->normalizeUsername((string) $item['username']) === $username;
    }

    private function relationshipItemMatchesUsernames(mixed $item, Collection $usernames): bool
    {
        if ($usernames->isEmpty() || ! is_array($item) || ! filled($item['username'] ?? null)) {
            return false;
        }

        return $usernames->contains($this->normalizeUsername((string) $item['username']));
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
            'profileVisibility' => $this->profileStatusForInstagramProfile($profile),
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
    ): ?array {
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
            'profileVisibility' => $this->profileStatusForRelationshipItem($item),
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

        if (
            ($nodes[$id]['type'] ?? null) !== 'person'
            && in_array($nodes[$id]['label'] ?? null, [null, '', '@'.$profile->username, $profile->display_handle], true)
        ) {
            $nodes[$id]['label'] = $profile->display_name ?: $profile->full_name ?: $profile->display_handle;
        }

        $nodes[$id]['detail'] = $this->mergeNodeDetail(
            $nodes[$id]['detail'] ?? null,
            $this->profileDetailForInstagramProfile($profile),
        );

        if (in_array($nodes[$id]['profileVisibility'] ?? 'unknown', ['unknown', 'listed', ''], true)) {
            $nodes[$id]['profileVisibility'] = $this->profileStatusForInstagramProfile($profile);
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

    private function profileStatusForTrackedPerson(TrackedPerson $person): string
    {
        $rawPayload = is_array($person->latestInstagramSnapshot?->raw_payload)
            ? $person->latestInstagramSnapshot->raw_payload
            : [];
        $visibility = Str::lower((string) data_get($rawPayload, 'extractedProfile.profileVisibility', ''));

        if (in_array($visibility, ['public', 'private'], true)) {
            return $visibility;
        }

        if (data_get($rawPayload, 'extractedProfile.isPrivate') === true) {
            return 'private';
        }

        if (data_get($rawPayload, 'extractedProfile.isPrivate') === false) {
            return 'public';
        }

        return 'unknown';
    }

    private function profileStatusForRelationshipItem(mixed $item): string
    {
        $visibility = Str::lower((string) data_get($item, 'profileVisibility', ''));

        if (in_array($visibility, ['public', 'private'], true)) {
            return $visibility;
        }

        if (data_get($item, 'isPrivate') === true) {
            return 'private';
        }

        if (data_get($item, 'isPrivate') === false) {
            return 'public';
        }

        return 'unknown';
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

    private function mergeTrackedRelationshipEdge(
        array &$edges,
        string $from,
        string $to,
        string $sourceLabel,
        string $detail,
        array $metadata = [],
    ): void {
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
                'systemWideEvidence' => (bool) ($metadata['systemWideEvidence'] ?? false),
                'ownUserEvidence' => (bool) ($metadata['ownUserEvidence'] ?? false),
                'otherUserEvidence' => (bool) ($metadata['otherUserEvidence'] ?? false),
                'systemEvidenceScanCount' => (int) ($metadata['systemEvidenceScanCount'] ?? 0),
                'systemEvidenceUserCount' => (int) ($metadata['systemEvidenceUserCount'] ?? 0),
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
        $edges[$edgeId]['systemWideEvidence'] = (bool) ($edges[$edgeId]['systemWideEvidence'] ?? false)
            || (bool) ($metadata['systemWideEvidence'] ?? false);
        $edges[$edgeId]['ownUserEvidence'] = (bool) ($edges[$edgeId]['ownUserEvidence'] ?? false)
            || (bool) ($metadata['ownUserEvidence'] ?? false);
        $edges[$edgeId]['otherUserEvidence'] = (bool) ($edges[$edgeId]['otherUserEvidence'] ?? false)
            || (bool) ($metadata['otherUserEvidence'] ?? false);
        $edges[$edgeId]['systemEvidenceScanCount'] = (int) ($edges[$edgeId]['systemEvidenceScanCount'] ?? 0)
            + (int) ($metadata['systemEvidenceScanCount'] ?? 0);
        $edges[$edgeId]['systemEvidenceUserCount'] = max(
            (int) ($edges[$edgeId]['systemEvidenceUserCount'] ?? 0),
            (int) ($metadata['systemEvidenceUserCount'] ?? 0),
        );
    }

    private function profileImageUrlForPerson(TrackedPerson $person): ?string
    {
        if (filled($person->instagram_profile_image_path)) {
            return PublicAssetUrl::storage($person->instagram_profile_image_path);
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

        return PublicAssetUrl::fromStorageOrRemote($profile->profile_image_path, $profile->profile_image_url);
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
        $width = 1800;
        $height = 1200;
        $centerX = $width / 2;
        $centerY = $height / 2;
        $positions = [];

        // Calculate connection count for each node to determine proximity
        $connectionCounts = $this->calculateConnectionCounts($nodes, $edges);

        $maxConnections = max(1, max(array_values($connectionCounts) ?: [1]));

        // Find primary person (center)
        $primaryNode = collect($nodes)->firstWhere('isPrimary', true);
        if ($primaryNode) {
            $positions[$primaryNode['id']] = [
                'x' => $centerX,
                'y' => $centerY,
            ];
        }

        // Separate nodes by type
        $people = array_values(array_filter($nodes, fn (array $node): bool => $node['type'] === 'person' && ! ($node['isPrimary'] ?? false)));
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
            $radius = count($people) <= 1 ? 0 : 160 + (int) floor($index / 18) * 95;
            $positions[$node['id']] = [
                'x' => $centerX + cos($angle) * $radius,
                'y' => $centerY + sin($angle) * $radius,
            ];
        }

        // Place profiles with adaptive radius based on connection density
        foreach ($profiles as $index => $node) {
            $connectionCount = $connectionCounts[$node['id']] ?? 0;
            $ring = (int) floor($index / 36);
            $ringStart = $ring * 36;
            $ringCount = min(36, count($profiles) - $ringStart);
            $angle = $this->angle($index - $ringStart, max(1, $ringCount), -75 + ($ring * 11));
            $connectionPull = min(125, (int) round(($connectionCount / $maxConnections) * 125));
            $radius = 240 + ($ring * 120) - $connectionPull;
            $positions[$node['id']] = [
                'x' => $centerX + cos($angle) * $radius,
                'y' => $centerY + sin($angle) * $radius,
            ];
        }

        // Place candidates in outer ring
        foreach ($candidates as $index => $node) {
            $ring = (int) floor($index / 44);
            $ringStart = $ring * 44;
            $ringCount = min(44, count($candidates) - $ringStart);
            $angle = $this->angle($index - $ringStart, max(1, $ringCount), -120 + ($ring * 9));
            $radius = 430 + ($ring * 120);
            $positions[$node['id']] = [
                'x' => $centerX + cos($angle) * $radius,
                'y' => $centerY + sin($angle) * $radius,
            ];
        }

        foreach ($nodes as $index => $node) {
            $position = $positions[$node['id']] ?? ['x' => $centerX, 'y' => $centerY];
            $connectionCount = $connectionCounts[$node['id']] ?? 0;
            $visualMetrics = $this->visualMetricsForNode($node, $connectionCount, $maxConnections);
            $nodes[$index] = [
                ...$node,
                'x' => round($position['x'], 1),
                'y' => round($position['y'], 1),
                'connectionCount' => $connectionCount,
                'nodeSize' => $visualMetrics['size'],
                'nodeFontSize' => $visualMetrics['fontSize'],
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

    private function visualMetricsForNode(array $node, int $connectionCount, int $maxConnections): array
    {
        $ratio = $maxConnections > 0 ? min(1, $connectionCount / $maxConnections) : 0;

        if ((bool) ($node['isPrimary'] ?? false)) {
            return [
                'size' => 112,
                'fontSize' => 14,
            ];
        }

        if (($node['type'] ?? null) === 'person') {
            return [
                'size' => (int) round(72 + ($ratio * 22)),
                'fontSize' => (int) round(12 + ($ratio * 1.5)),
            ];
        }

        if (($node['type'] ?? null) === 'candidate') {
            return [
                'size' => (int) round(42 + ($ratio * 34)),
                'fontSize' => (int) round(10 + ($ratio * 1.5)),
            ];
        }

        return [
            'size' => (int) round(46 + ($ratio * 42)),
            'fontSize' => (int) round(10 + ($ratio * 2)),
        ];
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
                $relationshipKey = min($sourceId, $targetId).'|'.max($sourceId, $targetId);
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

            $profile->forceFill([
                'last_status_level' => 'partial',
                'last_status_message' => 'Profil-Vollanalyse wurde als Hintergrund-Job eingereiht.',
            ])->save();

            ScanInstagramProfileJob::dispatch($trackedPerson->id, $profile->id, (int) $user->id);
            $this->dispatch('notification', type: 'success', message: 'Profil-Vollanalyse wurde als Hintergrund-Job gestartet');
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

    public function openProfilePreview(string $nodeId): void
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
            $this->dispatch('notification', type: 'error', message: 'Profil konnte nicht geoeffnet werden');

            return;
        }

        $profile->load([
            'listScans' => fn ($query) => $query->latest('scanned_at')->limit(5),
        ]);

        $trackedPerson = $this->getPrimaryTrackedPerson($user);
        $normalizedUsername = $this->normalizeUsername($profile->username);
        $knownPublicProfile = $trackedPerson
            ? $trackedPerson->publicProfiles()
                ->where('platform', 'instagram')
                ->where('username', $normalizedUsername)
                ->first()
            : null;
        $observedTrackedPerson = $user->trackedPeople()
            ->where(function ($query) use ($profile, $normalizedUsername) {
                $query->where('current_instagram_profile_id', $profile->id)
                    ->orWhereRaw(
                        "LOWER(TRIM(LEADING '@' FROM instagram_username)) = ?",
                        [$normalizedUsername],
                    );
            })
            ->first();

        $activeSourceRelationships = $profile->sourceRelationships()
            ->where('status', 'active')
            ->whereNull('removed_at');
        $activeRelatedRelationships = $profile->relatedRelationships()
            ->where('status', 'active')
            ->whereNull('removed_at');

        $this->previewNodeId = $nodeId;
        $this->profilePreview = [
            'id' => $profile->id,
            'detail_url' => route('instagram-profiles.show', ['instagramProfileId' => $profile->id]),
            'username' => $normalizedUsername,
            'handle' => $profile->display_handle,
            'display_name' => $profile->display_name ?: $profile->full_name ?: $profile->display_handle,
            'profile_url' => $profile->profile_url ?: 'https://www.instagram.com/'.$normalizedUsername.'/',
            'image_url' => $this->profileImageUrlForInstagramProfile($profile),
            'visibility' => $this->profileStatusForInstagramProfile($profile),
            'followers_count' => $profile->followers_count,
            'following_count' => $profile->following_count,
            'posts_count' => $profile->posts_count,
            'last_scanned_at' => $profile->last_scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'last_status_level' => $profile->last_status_level,
            'last_status_message' => $profile->last_status_message,
            'is_known_profile' => (bool) $knownPublicProfile,
            'tracked_person_id' => $observedTrackedPerson?->id,
            'known_public_profile_id' => $knownPublicProfile?->id,
            'active_followers_count' => (clone $activeSourceRelationships)->where('list_type', 'followers')->count(),
            'active_following_count' => (clone $activeSourceRelationships)->where('list_type', 'following')->count(),
            'known_incoming_count' => $activeRelatedRelationships->count(),
            'list_scans' => $profile->listScans
                ->map(fn ($scan): array => [
                    'id' => $scan->id,
                    'list_type' => $scan->list_type,
                    'status_level' => $scan->status_level,
                    'status_message' => $scan->status_message,
                    'active_count' => $scan->active_count,
                    'observed_count' => $scan->observed_count,
                    'scanned_at' => $scan->scanned_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                ])
                ->values()
                ->all(),
        ];

        $this->showProfilePreviewModal = true;
        $this->dispatch(
            'assistant-context-profile-preview',
            open: true,
            instagramProfileId: (int) $profile->id,
            username: $normalizedUsername,
            name: $this->profilePreview['display_name'],
            nodeId: $nodeId,
        );
    }

    public function closeProfilePreview(): void
    {
        $this->showProfilePreviewModal = false;
        $this->previewNodeId = null;
        $this->profilePreview = [];
        $this->dispatch('assistant-context-profile-preview', open: false);
    }

    public function addPreviewProfileAsKnown(): void
    {
        if (! $this->previewNodeId) {
            return;
        }

        $this->addProfileAsKnown($this->previewNodeId);
        $this->openProfilePreview($this->previewNodeId);
    }

    public function addPreviewProfileAsTrackedPerson(): void
    {
        $user = Auth::user();

        if (! $user || ! $this->previewNodeId) {
            return;
        }

        [$profile, $username] = $this->resolveInstagramProfileFromNodeId($this->previewNodeId);

        if (! $profile && filled($username)) {
            $profile = app(InstagramProfileRelationshipStore::class)->ensureProfile($username);
        }

        if (! $profile || ! filled($profile->username)) {
            $this->dispatch('notification', type: 'error', message: 'Profil konnte nicht als beobachtete Person angelegt werden');

            return;
        }

        $normalizedUsername = $this->normalizeUsername($profile->username);
        $existing = $user->trackedPeople()
            ->where(function ($query) use ($profile, $normalizedUsername) {
                $query->where('current_instagram_profile_id', $profile->id)
                    ->orWhereRaw(
                        "LOWER(TRIM(LEADING '@' FROM instagram_username)) = ?",
                        [$normalizedUsername],
                    );
            })
            ->first();

        if (! $existing) {
            try {
                app(TrackedPersonQuotaService::class)->assertCanCreate($user);
                $displayName = trim((string) ($profile->display_name ?: $profile->full_name ?: $normalizedUsername));
                $nameParts = preg_split('/\s+/', $displayName, 2) ?: [];
                $existing = $user->trackedPeople()->create([
                    'first_name' => $nameParts[0] ?? $normalizedUsername,
                    'last_name' => $nameParts[1] ?? '',
                    'alias' => $displayName,
                    'instagram_username' => $normalizedUsername,
                    'current_instagram_profile_id' => $profile->id,
                    'is_primary' => ! $user->trackedPeople()->where('is_primary', true)->exists(),
                ]);
                app(InstagramProfileRelationshipStore::class)->syncTrackedPersonProfile($existing);
            } catch (\Throwable $exception) {
                $this->dispatch('notification', type: 'error', message: $exception->getMessage());

                return;
            }
        }

        $this->forgetGraphCache((int) $user->id);
        $this->dispatch('notification', type: 'success', message: 'Profil wurde als beobachtete Person angelegt');
        $this->openProfilePreview($this->previewNodeId);
    }

    public function scanPreviewProfile(): void
    {
        if (! $this->previewNodeId) {
            return;
        }

        $this->scanProfileInGui($this->previewNodeId);
        $this->openProfilePreview($this->previewNodeId);
    }

    public function scanPreviewProfileInBackground(): void
    {
        if (! $this->previewNodeId) {
            return;
        }

        $this->scanProfile($this->previewNodeId);
        $this->openProfilePreview($this->previewNodeId);
    }

    public function scanProfileInGui(string $nodeId): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

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
            $progress = fn (array $state) => $this->streamNetworkMapScanProgress($state);

            $this->streamNetworkMapScanProgress([
                'phase' => 'profile-list',
                'percent' => 1,
                'message' => 'Profil-Vollanalyse wird vorbereitet.',
                'foundFollowers' => 0,
                'foundFollowing' => 0,
                'observedSuggestionCount' => 0,
            ]);

            $result = app(InstagramProfileScanService::class)->scan(
                $profile,
                (int) $user->id,
                true,
                $progress,
            );
            $this->forgetGraphCache((int) $user->id);
            $this->graphToken = null;
            $this->prepareGraph();
            $this->dispatch(
                'notification',
                type: $result['statusLevel'] === 'success' ? 'success' : 'warning',
                message: $result['statusMessage'],
            );
        } catch (\Throwable $e) {
            $this->streamNetworkMapScanProgress([
                'phase' => 'error',
                'percent' => 100,
                'message' => 'Profil-Vollanalyse fehlgeschlagen.',
            ]);
            $this->dispatch('notification', type: 'error', message: 'Profil-Vollanalyse fehlgeschlagen: '.$e->getMessage());
        }
    }

    private function streamNetworkMapScanProgress(array $state): void
    {
        $phase = match ($state['phase'] ?? 'analysis') {
            'public-connections' => 'Verbindungen',
            'profile-list' => 'Profil-Listen',
            'followers' => 'Followerliste',
            'following' => 'Gefolgt-Liste',
            'suggestions' => 'Vorschlaege',
            'saving' => 'Speichern',
            'done' => 'Fertig',
            'error' => 'Fehler',
            default => 'Scan',
        };
        $percent = max(0, min(100, (int) ($state['percent'] ?? 0)));
        $message = (string) ($state['message'] ?? 'Instagram-Scan laeuft.');
        $loaded = $state['loaded'] ?? null;
        $expected = $state['expected'] ?? null;
        $foundFollowers = $state['foundFollowers'] ?? null;
        $foundFollowing = $state['foundFollowing'] ?? null;
        $foundSuggestions = $state['foundSuggestions'] ?? null;
        $observedSuggestionCount = $state['observedSuggestionCount'] ?? null;
        $knownSuggestionCount = $state['knownSuggestionCount'] ?? null;
        $skippedSuggestions = $state['skippedSuggestions'] ?? null;
        $liveCounts = [];

        if ($loaded !== null && $expected !== null) {
            $liveCounts[] = 'Geprueft: '.number_format((int) $loaded, 0, ',', '.').' / '.number_format((int) $expected, 0, ',', '.');
        }

        if ($foundFollowers !== null || $foundFollowing !== null) {
            $liveCounts[] = 'Gefunden: '
                .number_format((int) $foundFollowers, 0, ',', '.').' Follower / '
                .number_format((int) $foundFollowing, 0, ',', '.').' Gefolgt';
        }

        if ($foundSuggestions !== null) {
            $liveCounts[] = 'Vorschlag-Verbindungen: '.number_format((int) $foundSuggestions, 0, ',', '.');
        }

        if ($observedSuggestionCount !== null) {
            $suggestionCountText = 'Vorschlaege gesehen: '.number_format((int) $observedSuggestionCount, 0, ',', '.');

            if ($knownSuggestionCount !== null || $skippedSuggestions !== null) {
                $suggestionCountText .= ' (bekannt/uebersprungen: '
                    .number_format((int) max((int) $knownSuggestionCount, (int) $skippedSuggestions), 0, ',', '.')
                    .')';
            }

            $liveCounts[] = $suggestionCountText;
        }

        $this->stream('network-map-scan-phase', e($phase), true);
        $this->stream('network-map-scan-message', e($message), true);
        $this->stream('network-map-scan-live-counts', e(implode(' | ', $liveCounts)), true);
        $this->stream('network-map-scan-percent', $percent.'%', true);
        $this->stream(
            'network-map-scan-bar',
            '<div class="h-full rounded-full bg-pink-600 transition-all duration-300" style="width: '.$percent.'%"></div>',
            true,
        );

        $screenshotUrl = is_scalar($state['liveScreenshotUrl'] ?? null)
            ? trim((string) $state['liveScreenshotUrl'])
            : '';

        if ($screenshotUrl !== '') {
            $this->stream(
                'network-map-scan-live-preview',
                '<div class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-left">'
                .'<div class="flex items-center justify-between border-b border-slate-200 bg-white px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">'
                .'<span>Browser-Vorschau</span><span>Live-Screenshot</span></div>'
                .'<img src="'.e($screenshotUrl).'" alt="Aktuelle Browser-Vorschau des Instagram-Scans" class="block aspect-video w-full bg-slate-100 object-contain">'
                .'</div>',
                true,
            );
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
        self::forgetGraphCacheForUser($userId, $this->contextTrackedPersonId);
    }

    /**
     * Get the primary tracked person for a user.
     */
    private function getPrimaryTrackedPerson(User $user): ?TrackedPerson
    {
        if ($this->contextTrackedPersonId) {
            $contextPerson = $user->trackedPeople()
                ->whereKey($this->contextTrackedPersonId)
                ->first();

            if ($contextPerson) {
                return $contextPerson;
            }
        }

        return $user->trackedPeople()
            ->orderByDesc('is_primary')
            ->first();
    }
}
