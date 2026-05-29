<?php

namespace App\Livewire\User;

use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

class NetworkMap extends Component
{
    public ?int $primaryTrackedPersonId = null;

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

        $this->primaryTrackedPersonId = $trackedPersonId;
        $this->dispatch('network-map-refresh');
    }

    public function render()
    {
        $user = Auth::user();
        $trackedPeople = $user
            ? $user->trackedPeople()
                ->with([
                    'latestInstagramSnapshot',
                    'publicProfiles.latestInstagramConnectionScan',
                    'instagramInferredConnections.publicProfile',
                ])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
            : collect();

        $this->primaryTrackedPersonId = $this->resolvePrimaryTrackedPersonId($trackedPeople);

        $graph = $this->buildGraph($trackedPeople);

        return view('livewire.user.network-map', [
            'trackedPeople' => $trackedPeople,
            'graph' => $graph,
            'stats' => [
                'people' => $trackedPeople->count(),
                'nodes' => count($graph['nodes']),
                'edges' => count($graph['edges']),
                'inferred' => collect($graph['edges'])->where('type', 'inferred')->count(),
                'trackedList' => collect($graph['edges'])->where('type', 'tracked-list')->count(),
            ],
        ])->layout('layouts.app');
    }

    private function buildGraph(Collection $trackedPeople): array
    {
        $nodes = [];
        $edges = [];
        $peopleByInstagram = $trackedPeople
            ->filter(fn (TrackedPerson $person): bool => filled($person->instagram_username))
            ->mapWithKeys(fn (TrackedPerson $person): array => [
                $this->normalizeUsername($person->instagram_username) => $person,
            ]);

        foreach ($trackedPeople as $person) {
            $imageUrl = $this->profileImageUrlForPerson($person);

            $nodes['person-'.$person->id] = [
                'id' => 'person-'.$person->id,
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
            ];
        }

        foreach ($trackedPeople as $person) {
            $sourceId = 'person-'.$person->id;

            foreach ($person->publicProfiles as $publicProfile) {
                if (! filled($publicProfile->username)) {
                    continue;
                }

                $profileUsername = $this->normalizeUsername($publicProfile->username);
                $targetPerson = $peopleByInstagram->get($profileUsername);
                $targetId = $targetPerson ? 'person-'.$targetPerson->id : 'profile-'.$publicProfile->platform.'-'.$profileUsername;

                if (! isset($nodes[$targetId])) {
                    $nodes[$targetId] = [
                        'id' => $targetId,
                        'type' => 'profile',
                        'label' => $publicProfile->display_name ?: $publicProfile->display_handle,
                        'handle' => $publicProfile->display_handle,
                        'username' => $profileUsername,
                        'platform' => $publicProfile->platform,
                        'imageUrl' => null,
                        'hasImage' => false,
                        'isPrimary' => false,
                        'role' => 'Bekanntes Profil',
                        'status' => $publicProfile->is_public ? 'public' : 'unknown',
                        'detail' => $publicProfile->relationship_label,
                    ];
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

            foreach ($person->instagramInferredConnections as $connection) {
                if (! filled($connection->candidate_username)) {
                    continue;
                }

                $candidateUsername = $this->normalizeUsername($connection->candidate_username);
                $candidatePerson = $peopleByInstagram->get($candidateUsername);
                $candidateId = $candidatePerson ? 'person-'.$candidatePerson->id : 'candidate-'.$candidateUsername;

                if (! isset($nodes[$candidateId])) {
                    $nodes[$candidateId] = [
                        'id' => $candidateId,
                        'type' => 'candidate',
                        'label' => $connection->candidate_display_name ?: '@'.$candidateUsername,
                        'handle' => '@'.$candidateUsername,
                        'username' => $candidateUsername,
                        'platform' => 'instagram',
                        'imageUrl' => null,
                        'hasImage' => false,
                        'isPrimary' => false,
                        'role' => 'Rekonstruierter Kandidat',
                        'status' => 'inferred',
                        'detail' => $connection->relationship_label,
                    ];
                }

                $isFollower = $connection->relationship_type === 'follows_target';
                $from = $isFollower ? $candidateId : $sourceId;
                $to = $isFollower ? $sourceId : $candidateId;
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

        $this->addTrackedRelationshipListEdges(
            $trackedPeople,
            $this->indexGraphNodesByInstagramUsername($nodes),
            $edges,
        );

        return $this->applyLayout(array_values($nodes), array_values($edges));
    }

    private function resolvePrimaryTrackedPersonId(Collection $trackedPeople): ?int
    {
        if ($trackedPeople->isEmpty()) {
            return null;
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

    private function addTrackedRelationshipListEdges(Collection $trackedPeople, Collection $nodesByInstagram, array &$edges): void
    {
        foreach ($trackedPeople as $person) {
            $sourceId = 'person-'.$person->id;

            foreach ($this->loadSnapshotRelationshipItems($person->latestInstagramSnapshot, 'followersList') as $item) {
                $targetNode = $this->nodeForRelationshipItem($nodesByInstagram, $item);

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
                        $this->displayUsernameForPerson($person),
                    ),
                );
            }

            foreach ($this->loadSnapshotRelationshipItems($person->latestInstagramSnapshot, 'followingList') as $item) {
                $targetNode = $this->nodeForRelationshipItem($nodesByInstagram, $item);

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
                        $this->displayUsernameForPerson($person),
                    ),
                );
            }
        }
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
                'candidate' => 2,
                'profile' => 1,
                default => 0,
            });

        foreach ($prioritizedNodes as $node) {
            if (! $indexed->has($node['username'])) {
                $indexed->put($node['username'], $node);
            }
        }

        return $indexed;
    }

    private function nodeForRelationshipItem(Collection $nodesByInstagram, mixed $item): ?array
    {
        if (! is_array($item) || ! filled($item['username'] ?? null)) {
            return null;
        }

        return $nodesByInstagram->get($this->normalizeUsername((string) $item['username']));
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
        if (filled($person->profile_image_url)) {
            return $person->profile_image_url;
        }

        if (filled($person->instagram_profile_image_path)) {
            return Storage::disk('public')->url($person->instagram_profile_image_path);
        }

        if (filled($person->latestInstagramSnapshot?->profile_image_storage_url)) {
            return $person->latestInstagramSnapshot->profile_image_storage_url;
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
        $people = array_values(array_filter($nodes, fn (array $node): bool => $node['type'] === 'person'));
        $others = array_values(array_filter($nodes, fn (array $node): bool => $node['type'] !== 'person'));
        $positions = [];

        foreach ($people as $index => $node) {
            if ($node['isPrimary'] ?? false) {
                $positions[$node['id']] = [
                    'x' => $centerX,
                    'y' => $centerY,
                ];

                continue;
            }

            $angle = $this->angle($index, max(1, count($people)), -90);
            $radius = count($people) <= 1 ? 0 : min(260, 130 + count($people) * 16);
            $positions[$node['id']] = [
                'x' => $centerX + cos($angle) * $radius,
                'y' => $centerY + sin($angle) * $radius,
            ];
        }

        foreach ($others as $index => $node) {
            $angle = $this->angle($index, max(1, count($others)), -75);
            $radius = min(340, 210 + count($others) * 4);
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

    private function angle(int $index, int $count, float $offsetDegrees = 0): float
    {
        return deg2rad($offsetDegrees + (($index / max(1, $count)) * 360));
    }

    private function normalizeUsername(?string $username): string
    {
        return Str::lower(ltrim(trim((string) $username), '@'));
    }
}
