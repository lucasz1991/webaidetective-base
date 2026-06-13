<?php

namespace Tests\Unit;

use App\Livewire\User\NetworkMap;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NetworkMapGraphLimitsTest extends TestCase
{
    public function test_it_limits_the_graph_to_250_nodes_including_the_focus_profile(): void
    {
        [$nodes, $edges] = $this->graphWithProfiles(300);

        [$limitedNodes, $limitedEdges] = $this->invokePrivate(
            new NetworkMap,
            'limitGraphProfiles',
            [$nodes, $edges],
        );

        $profileNodes = array_filter(
            $limitedNodes,
            fn (array $node): bool => $node['type'] !== 'person',
        );
        $nodeIds = array_column($limitedNodes, 'id');

        $this->assertCount(250, $limitedNodes);
        $this->assertCount(249, $profileNodes);
        $this->assertContains('person-1', $nodeIds);
        $this->assertContains('profile-001', $nodeIds);
        $this->assertNotContains('profile-300', $nodeIds);
        $this->assertTrue(collect($limitedEdges)->every(
            fn (array $edge): bool => in_array($edge['from'], $nodeIds, true)
                && in_array($edge['to'], $nodeIds, true),
        ));
    }

    public function test_it_keeps_images_for_only_50_closest_contacts(): void
    {
        [$nodes, $edges] = $this->graphWithProfiles(80);
        $limitedNodes = $this->invokePrivate(
            new NetworkMap,
            'limitGraphImages',
            [$nodes, $edges],
        );

        $contactImages = collect($limitedNodes)
            ->reject(fn (array $node): bool => $node['id'] === 'person-1')
            ->filter(fn (array $node): bool => $node['hasImage'])
            ->values();

        $this->assertCount(50, $contactImages);
        $this->assertTrue($limitedNodes['person-1']['hasImage']);
        $this->assertTrue($limitedNodes['profile-001']['hasImage']);
        $this->assertFalse($limitedNodes['profile-080']['hasImage']);
        $this->assertNull($limitedNodes['profile-080']['imageUrl']);
    }

    public function test_it_prioritizes_direct_focus_connections_when_the_graph_is_limited(): void
    {
        [$nodes, $edges] = $this->graphWithProfiles(300);

        foreach (range(101, 300) as $index) {
            $id = sprintf('profile-%03d', $index);
            $edges['edge-'.$id] = [
                'id' => 'edge-'.$id,
                'from' => 'person-1',
                'to' => $id,
            ];
        }

        $nodes['incoming-profile'] = [
            'id' => 'incoming-profile',
            'type' => 'profile',
            'label' => 'ZZZ incoming profile',
            'isKnownProfile' => false,
            'isDirectFocusConnection' => true,
            'imageUrl' => null,
            'hasImage' => false,
        ];
        $edges['edge-incoming-profile'] = [
            'id' => 'edge-incoming-profile',
            'from' => 'incoming-profile',
            'to' => 'person-1',
        ];

        [$limitedNodes, $limitedEdges] = $this->invokePrivate(
            new NetworkMap,
            'limitGraphProfiles',
            [$nodes, $edges],
        );

        $nodeIds = array_column($limitedNodes, 'id');

        $this->assertContains('incoming-profile', $nodeIds);
        $this->assertTrue(collect($limitedEdges)->contains(
            fn (array $edge): bool => $edge['id'] === 'edge-incoming-profile',
        ));
    }

    public function test_suggestion_connections_are_not_emitted_as_confirmed_list_edges(): void
    {
        $edge = $this->invokePrivate(
            new NetworkMap,
            'suggestionConnectionEdge',
            [17, 'profile-source', 'person-1', '@source', '@source enthaelt @focus als Vorschlag.', false],
        );

        $this->assertSame('inferred', $edge['type']);
        $this->assertSame('Vorschlag-Verbindung', $edge['label']);
        $this->assertStringContainsString('suggestion_connection', $edge['id']);
        $this->assertFalse($edge['ownUserEvidence']);
        $this->assertTrue($edge['otherUserEvidence']);
    }

    private function graphWithProfiles(int $profileCount): array
    {
        $nodes = [
            'person-1' => [
                'id' => 'person-1',
                'type' => 'person',
                'label' => 'Fokusperson',
                'isPrimary' => true,
                'isFocus' => true,
                'imageUrl' => '/focus.jpg',
                'hasImage' => true,
            ],
        ];
        $edges = [];

        for ($index = 1; $index <= $profileCount; $index++) {
            $id = sprintf('profile-%03d', $index);
            $nodes[$id] = [
                'id' => $id,
                'type' => 'profile',
                'label' => sprintf('Profile %03d', $index),
                'isKnownProfile' => $index <= 20,
                'imageUrl' => '/'.$id.'.jpg',
                'hasImage' => true,
            ];

            if ($index <= 100) {
                $edges['edge-'.$id] = [
                    'id' => 'edge-'.$id,
                    'from' => 'person-1',
                    'to' => $id,
                ];
            }
        }

        return [$nodes, $edges];
    }

    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
