<?php
namespace Neos\ContentRepository\Security\Authorization\Privilege\Node;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\DimensionSpace\Dimension\ContentDimensionIdentifier;
use Neos\ContentRepository\SharedModel\Node\NodeAggregateIdentifier;
use Neos\ContentRepository\NodeAccess\NodeAccessor\NodeAccessorInterface;
use Neos\ContentRepository\NodeAccess\NodeAccessorManager;
use Neos\ContentRepository\SharedModel\VisibilityConstraints;
use Neos\ContentRepository\Projection\ContentGraph\NodeInterface;
use Neos\ContentRepository\Projection\Workspace\WorkspaceFinder;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * An Eel context matching expression for the node privileges
 */
class NodePrivilegeContext
{
    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var NodeAccessorManager
     */
    protected $nodeAccessorManager;

    protected NodeInterface $node;

    protected ?NodeAccessorInterface $nodeAccessor;

    /**
     * @Flow\Inject
     * @var WorkspaceFinder
     */
    protected $workspaceFinder;

    public function __construct(NodeInterface $node)
    {
        $this->node = $node;
    }

    /**
     * Matches if the selected node is an *ancestor* of the given node specified by $nodePathOrIdentifier
     *
     * Example: isAncestorNodeOf('/sites/some/path') matches for the nodes "/sites",
     *  "/sites/some" and "/sites/some/path" but not for "/sites/some/other"
     *
     * @param string $nodePathOrIdentifier The identifier or absolute path of the node to match
     * @return boolean true if the given node matches otherwise false
     */
    public function isAncestorNodeOf(string $nodePathOrIdentifier): bool
    {
        $nodePathOrResult = $this->resolveNodePathOrResult($nodePathOrIdentifier);
        if (is_bool($nodePathOrResult)) {
            return $nodePathOrResult;
        }

        return str_starts_with($nodePathOrResult, (string)$this->getNodeAccessor()->findNodePath($this->node));
    }

    /**
     * Matches if the selected node is a *descendant* of the given node specified by $nodePathOrIdentifier
     *
     * Example: isDescendantNodeOf('/sites/some/path') matches for the nodes "/sites/some/path",
     * "/sites/some/path/subnode" but not for "/sites/some/other"
     *
     * @param string $nodePathOrIdentifier The identifier or absolute path of the node to match
     * @return bool true if the given node matches otherwise false
     */
    public function isDescendantNodeOf(string $nodePathOrIdentifier): bool
    {
        $nodePathOrResult = $this->resolveNodePathOrResult($nodePathOrIdentifier);
        if (is_bool($nodePathOrResult)) {
            return $nodePathOrResult;
        }

        return str_starts_with((string)$this->getNodeAccessor()->findNodePath($this->node), $nodePathOrResult);
    }

    /**
     * Matches if the selected node is a *descendant* or *ancestor* of the given node specified by $nodePathOrIdentifier
     *
     * Example: isAncestorOrDescendantNodeOf('/sites/some') matches for the nodes "/sites", "/sites/some",
     * "/sites/some/sub" but not "/sites/other"
     *
     * @param string $nodePathOrIdentifier The identifier or absolute path of the node to match
     * @return bool true if the given node matches otherwise false
     */
    public function isAncestorOrDescendantNodeOf(string $nodePathOrIdentifier): bool
    {
        return $this->isAncestorNodeOf($nodePathOrIdentifier) || $this->isDescendantNodeOf($nodePathOrIdentifier);
    }

    /**
     * Matches if the selected node is of the given NodeType(s).
     * If multiple types are specified, only one entry has to match
     *
     * Example: nodeIsOfType(['Neos.ContentRepository:NodeType1', 'Neos.ContentRepository:NodeType2'] matches,
     * if the selected node is of (sub) type *Neos.ContentRepository:NodeType1* or *Neos.ContentRepository:NodeType1*
     *
     * @param string|array<int,string> $nodeTypes A single or an array of fully qualified NodeType name(s),
     * e.g. "Neos.Neos:Document"
     * @return bool true if the selected node matches the $nodeTypes, otherwise false
     */
    public function nodeIsOfType(string|array $nodeTypes): bool
    {
        if (!is_array($nodeTypes)) {
            $nodeTypes = [$nodeTypes];
        }

        foreach ($nodeTypes as $nodeType) {
            if ($this->node->getNodeType()->isOfType($nodeType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Matches if the selected node belongs to one of the given $workspaceNames
     *
     * Example: isInWorkspace(['live', 'user-admin']) matches,
     * if the selected node is in one of the workspaces "user-admin" or "live"
     *
     * @param array<int,string> $workspaceNames An array of workspace names, e.g. ["live", "user-admin"]
     * @return bool true if the selected node matches the $workspaceNames, otherwise false
     */
    public function isInWorkspace(array $workspaceNames): bool
    {
        $workspace = $this->workspaceFinder->findOneByCurrentContentStreamIdentifier(
            $this->node->getSubgraphIdentity()->contentStreamIdentifier
        );
        return !is_null($workspace) && in_array((string)$workspace->getWorkspaceName(), $workspaceNames);
    }

    /**
     * Matches if the currently-selected preset in the passed $dimensionName is one of $presets.
     *
     * Example: isInDimensionPreset('language', 'de') checks whether the currently-selected language
     * preset (in the Neos backend) is "de".
     *
     * Implementation Note: We deliberately work on the Dimension Preset Name, and not on the
     * dimension values itself; as the preset is user-visible and the actual dimension-values
     * for a preset are just implementation details.
     *
     * @param string|array<int,string> $presets
     */
    public function isInDimensionPreset(string $dimensionName, string|array $presets): bool
    {
        if (!is_array($presets)) {
            $presets = [$presets];
        }

        return in_array(
            $this->node->getSubgraphIdentity()->dimensionSpacePoint->getCoordinate(
                new ContentDimensionIdentifier($dimensionName)
            ),
            $presets
        );
    }

    /**
     * Resolves the given $nodePathOrIdentifier and returns its absolute path and or a boolean,
     * if the result directly matches the currently selected node
     *
     * @param string $nodePathOrIdentifier identifier or absolute path for the node to resolve
     * @return bool|string true if the node matches the selected node, false if the corresponding node does not exist.
     * Otherwise the resolved absolute path with trailing slash
     */
    protected function resolveNodePathOrResult(string $nodePathOrIdentifier): bool|string
    {
        try {
            $nodeAggregateIdentifier = NodeAggregateIdentifier::fromString($nodePathOrIdentifier);
            if ($nodeAggregateIdentifier->equals($this->node->getNodeAggregateIdentifier())) {
                return true;
            }
            $otherNode = $this->getNodeAccessor()->findByIdentifier($nodeAggregateIdentifier);
            if (is_null($otherNode)) {
                return false;
            }
            return $this->getNodeAccessor()->findNodePath($otherNode) . '/';
        } catch (\InvalidArgumentException $e) {
            return rtrim($nodePathOrIdentifier, '/') . '/';
        }
    }

    private function getNodeAccessor(): NodeAccessorInterface
    {
        if (is_null($this->nodeAccessor)) {
            $this->nodeAccessor = $this->nodeAccessorManager->accessorFor(
                $this->node->getSubgraphIdentity()
            );
        }

        return $this->nodeAccessor;
    }
}
