<?php
namespace Neos\ContentRepository\Security\Service;

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Projection\ContentGraph\Node;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\CreateNodePrivilege;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\CreateNodePrivilegeSubject;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\EditNodePrivilege;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\EditNodePropertyPrivilege;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\NodePrivilegeSubject;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\PropertyAwareNodePrivilegeSubject;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\ReadNodePropertyPrivilege;
use Neos\ContentRepository\Security\Authorization\Privilege\Node\RemoveNodePrivilege;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Context;
use Neos\ContentRepository\SharedModel\NodeType\NodeType;

/**
 * This service provides API methods to check for privileges
 * on nodes and permissions for node actions.
 *
 * @Flow\Scope("singleton")
 */
class AuthorizationService
{
    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var PrivilegeManagerInterface
     */
    protected $privilegeManager;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * Returns true if the currently authenticated user is allowed to edit the given $node, otherwise false
     *
     * @param Node $node
     * @return boolean
     */
    public function isGrantedToEditNode(Node $node)
    {
        return $this->privilegeManager->isGranted(EditNodePrivilege::class, new NodePrivilegeSubject($node));
    }

    /**
     * Returns true if the currently authenticated user is allowed to create a node of type $typeOfNewNode within the given $referenceNode
     *
     * @param Node $referenceNode
     * @param NodeType $typeOfNewNode
     * @return boolean
     */
    public function isGrantedToCreateNode(Node $referenceNode, NodeType $typeOfNewNode = null)
    {
        return $this->privilegeManager->isGranted(CreateNodePrivilege::class, new CreateNodePrivilegeSubject($referenceNode, $typeOfNewNode));
    }

    /**
     * Returns the node types that the currently authenticated user is *denied* to create within the given $referenceNode
     *
     * @param Node $referenceNode
     * @return string[] Array of granted node type names
     */
    public function getNodeTypeNamesDeniedForCreation(Node $referenceNode)
    {
        $privilegeSubject = new CreateNodePrivilegeSubject($referenceNode);

        $contentRepository = $this->contentRepositoryRegistry->get($referenceNode->subgraphIdentity->contentRepositoryIdentifier);
        $allNodeTypes = $contentRepository->getNodeTypeManager()->getNodeTypes();

        $deniedCreationNodeTypes = [];
        $grantedCreationNodeTypes = [];
        $abstainedCreationNodeTypes = [];
        foreach ($this->securityContext->getRoles() as $role) {
            /** @var CreateNodePrivilege $createNodePrivilege */
            foreach ($role->getPrivilegesByType(CreateNodePrivilege::class) as $createNodePrivilege) {
                if (!$createNodePrivilege->matchesSubject($privilegeSubject)) {
                    continue;
                }

                $affectedNodeTypes = ($createNodePrivilege->getCreationNodeTypes() !== [] ? $createNodePrivilege->getCreationNodeTypes() : $allNodeTypes);

                if ($createNodePrivilege->isGranted()) {
                    $grantedCreationNodeTypes = array_merge($grantedCreationNodeTypes, $affectedNodeTypes);
                } elseif ($createNodePrivilege->isDenied()) {
                    $deniedCreationNodeTypes = array_merge($deniedCreationNodeTypes, $affectedNodeTypes);
                } else {
                    $abstainedCreationNodeTypes = array_merge($abstainedCreationNodeTypes, $affectedNodeTypes);
                }
            }
        }
        $implicitlyDeniedNodeTypes = array_diff($abstainedCreationNodeTypes, $grantedCreationNodeTypes);
        return array_merge($implicitlyDeniedNodeTypes, $deniedCreationNodeTypes);
    }

    /**
     * Returns true if the currently authenticated user is allowed to remove the given $node
     *
     * @param Node $node
     * @return boolean
     */
    public function isGrantedToRemoveNode(Node $node)
    {
        $privilegeSubject = new NodePrivilegeSubject($node);
        return $this->privilegeManager->isGranted(RemoveNodePrivilege::class, $privilegeSubject);
    }

    /**
     * @param Node $node
     * @param string $propertyName
     * @return boolean
     */
    public function isGrantedToReadNodeProperty(Node $node, $propertyName)
    {
        $privilegeSubject = new PropertyAwareNodePrivilegeSubject($node, null, $propertyName);
        return $this->privilegeManager->isGranted(ReadNodePropertyPrivilege::class, $privilegeSubject);
    }

    /**
     * @param Node $node
     * @param string $propertyName
     * @return boolean
     */
    public function isGrantedToEditNodeProperty(Node $node, $propertyName)
    {
        $privilegeSubject = new PropertyAwareNodePrivilegeSubject($node, null, $propertyName);
        return $this->privilegeManager->isGranted(EditNodePropertyPrivilege::class, $privilegeSubject);
    }

    /**
     * @param Node $node
     * @return string[] Array of granted node property names
     */
    public function getDeniedNodePropertiesForEditing(Node $node)
    {
        $privilegeSubject = new PropertyAwareNodePrivilegeSubject($node);

        $deniedNodePropertyNames = [];
        $grantedNodePropertyNames = [];
        $abstainedNodePropertyNames = [];
        foreach ($this->securityContext->getRoles() as $role) {
            /** @var EditNodePropertyPrivilege $editNodePropertyPrivilege */
            foreach ($role->getPrivilegesByType(EditNodePropertyPrivilege::class) as $editNodePropertyPrivilege) {
                if (!$editNodePropertyPrivilege->matchesSubject($privilegeSubject)) {
                    continue;
                }
                if ($editNodePropertyPrivilege->isGranted()) {
                    $grantedNodePropertyNames = array_merge($grantedNodePropertyNames, $editNodePropertyPrivilege->getNodePropertyNames());
                } elseif ($editNodePropertyPrivilege->isDenied()) {
                    $deniedNodePropertyNames = array_merge($deniedNodePropertyNames, $editNodePropertyPrivilege->getNodePropertyNames());
                } else {
                    $abstainedNodePropertyNames = array_merge($abstainedNodePropertyNames, $editNodePropertyPrivilege->getNodePropertyNames());
                }
            }
        }

        $implicitlyDeniedNodePropertyNames = array_diff($abstainedNodePropertyNames, $grantedNodePropertyNames);
        return array_merge($implicitlyDeniedNodePropertyNames, $deniedNodePropertyNames);
    }
}
