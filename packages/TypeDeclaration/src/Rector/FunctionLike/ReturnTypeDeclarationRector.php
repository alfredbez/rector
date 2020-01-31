<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\FunctionLike;

use Iterator;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Throw_;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;
use Rector\TypeDeclaration\TypeInferer\ReturnTypeInferer;
use Rector\TypeDeclaration\TypeInferer\ReturnTypeInferer\ReturnTypeDeclarationReturnTypeInferer;
use Rector\ValueObject\PhpVersionFeature;

/**
 * @sponsor Thanks https://spaceflow.io/ for sponsoring this rule - visit them on https://github.com/SpaceFlow-app
 *
 * @see \Rector\TypeDeclaration\Tests\Rector\FunctionLike\ReturnTypeDeclarationRector\ReturnTypeDeclarationRectorTest
 */
final class ReturnTypeDeclarationRector extends AbstractTypeDeclarationRector
{
    /**
     * @var string[]
     */
    private const EXCLUDED_METHOD_NAMES = ['__construct', '__destruct', '__clone'];

    /**
     * @var string
     */
    private const DO_NOT_CHANGE = 'do_not_change';

    /**
     * @var ReturnTypeInferer
     */
    private $returnTypeInferer;

    /**
     * @var bool
     */
    private $overrideExistingReturnTypes = true;

    public function __construct(ReturnTypeInferer $returnTypeInferer, bool $overrideExistingReturnTypes = true)
    {
        $this->returnTypeInferer = $returnTypeInferer;
        $this->overrideExistingReturnTypes = $overrideExistingReturnTypes;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Change @return types and type from static analysis to type declarations if not a BC-break',
            [
                new CodeSample(
                    <<<'PHP'
<?php

class SomeClass
{
    /**
     * @return int
     */
    public function getCount()
    {
    }
}
PHP
                    ,
                    <<<'PHP'
<?php

class SomeClass
{
    /**
     * @return int
     */
    public function getCount(): int
    {
    }
}
PHP
                ),
            ]
        );
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->shouldSkip($node)) {
            return null;
        }

        $inferedType = $this->returnTypeInferer->inferFunctionLikeWithExcludedInferers(
            $node,
            [ReturnTypeDeclarationReturnTypeInferer::class]
        );

        if ($inferedType instanceof MixedType) {
            return null;
        }

        if ($this->isReturnTypeAlreadyAdded($node, $inferedType)) {
            return null;
        }

        $inferredReturnNode = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($inferedType);

        // nothing to change in PHP code - @todo add @var annotation fallback?
        if ($inferredReturnNode === null) {
            return null;
        }

        // prevent void overriding exception
        if ($this->isVoidDueToThrow($node, $inferredReturnNode)) {
            return null;
        }

        // already overridden by previous populateChild() method run
        if ($node->returnType && $node->returnType->getAttribute(self::DO_NOT_CHANGE)) {
            return null;
        }

        // should be previous overridden?
        if ($node->returnType !== null) {
            $isSubtype = $this->phpParserTypeAnalyzer->isSubtypeOf($inferredReturnNode, $node->returnType);

            $currentType = $this->staticTypeMapper->mapPhpParserNodePHPStanType($node->returnType);

            if ($node instanceof ClassMethod && $this->vendorLockResolver->isReturnChangeVendorLockedIn($node)) {
                return null;
            }

            if ($this->isCurrentObjectTypeSubType($currentType, $inferedType)) {
                return null;
            }

            if ($this->isNullableTypeSubType($currentType, $inferedType)) {
                return null;
            }

            if ($this->isAtLeastPhpVersion(PhpVersionFeature::COVARIANT_RETURN) && $isSubtype) {
                $node->returnType = $inferredReturnNode;
            } elseif (! $isSubtype) {
                // type override with correct one
                $node->returnType = $inferredReturnNode;
            }
        } else {
            $node->returnType = $inferredReturnNode;
        }

        if ($node instanceof ClassMethod) {
            $this->populateChildren($node, $inferedType);
        }

        return $node;
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    private function shouldSkip(Node $node): bool
    {
        if (! $this->isAtLeastPhpVersion(PhpVersionFeature::SCALAR_TYPES)) {
            return true;
        }

        if (! $this->overrideExistingReturnTypes && $node->returnType !== null) {
            return true;
        }

        if (! $node instanceof ClassMethod) {
            return false;
        }

        return $this->isNames($node, self::EXCLUDED_METHOD_NAMES);
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    private function isReturnTypeAlreadyAdded(Node $node, Type $returnType): bool
    {
        $returnNode = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($returnType);

        if ($node->returnType === null) {
            return false;
        }

        if ($this->areNodesEqual($node->returnType, $returnNode)) {
            return true;
        }

        // is array <=> iterable <=> Iterator co-type? → skip
        if ($this->isArrayIterableIteratorCoType($node, $returnType)) {
            return true;
        }

        // is class-string<T> type? → skip
        if ($returnType instanceof GenericObjectType && $returnType->getClassName() === 'class-string') {
            return true;
        }

        // prevent overriding self with itself
        if ($this->print($node->returnType) === 'self') {
            $className = $node->getAttribute(AttributeKey::CLASS_NAME);
            if (ltrim($this->print($returnNode), '\\') === $className) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add typehint to all children class methods
     */
    private function populateChildren(ClassMethod $classMethod, Type $returnType): void
    {
        $methodName = $this->getName($classMethod);
        if ($methodName === null) {
            throw new ShouldNotHappenException();
        }

        $className = $classMethod->getAttribute(AttributeKey::CLASS_NAME);
        if (! is_string($className)) {
            throw new ShouldNotHappenException();
        }

        $childrenClassLikes = $this->classLikeParsedNodesFinder->findChildrenOfClass($className);
        if ($childrenClassLikes === []) {
            return;
        }

        // update their methods as well
        foreach ($childrenClassLikes as $childClassLike) {
            $usedTraits = $this->classLikeParsedNodesFinder->findUsedTraitsInClass($childClassLike);
            foreach ($usedTraits as $trait) {
                $this->addReturnTypeToChildMethod($trait, $classMethod, $returnType);
            }

            $this->addReturnTypeToChildMethod($childClassLike, $classMethod, $returnType);
        }
    }

    private function isArrayIterableIteratorCoType(Node $node, Type $returnType): bool
    {
        if (! $this->isNames($node->returnType, ['iterable', 'Iterator', 'Traversable', 'array'])) {
            return false;
        }

        return $this->isStaticTypeIterable($returnType);
    }

    private function addReturnTypeToChildMethod(
        ClassLike $classLike,
        ClassMethod $classMethod,
        Type $returnType
    ): void {
        $methodName = $this->getName($classMethod);

        $currentClassMethod = $classLike->getMethod($methodName);
        if ($currentClassMethod === null) {
            return;
        }

        $resolvedChildTypeNode = $this->resolveChildTypeNode($returnType);
        if ($resolvedChildTypeNode === null) {
            return;
        }

        $currentClassMethod->returnType = $resolvedChildTypeNode;

        // make sure the type is not overridden
        $currentClassMethod->returnType->setAttribute(self::DO_NOT_CHANGE, true);

        $this->notifyNodeChangeFileInfo($currentClassMethod);
    }

    private function isStaticTypeIterable(Type $type): bool
    {
        if ($type instanceof ArrayType) {
            return true;
        }

        if ($type instanceof IterableType) {
            return true;
        }

        if ($type instanceof ObjectType && $type->getClassName() === Iterator::class) {
            return true;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->getTypes() as $joinedType) {
                if (! $this->isStaticTypeIterable($joinedType)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * E.g. current E, new type A, E extends A → true
     */
    private function isCurrentObjectTypeSubType(Type $currentType, Type $inferedType): bool
    {
        if (! $currentType instanceof ObjectType) {
            return false;
        }

        if (! $inferedType instanceof ObjectType) {
            return false;
        }

        return is_a($currentType->getClassName(), $inferedType->getClassName(), true);
    }

    private function isNullableTypeSubType(Type $currentType, Type $inferedType): bool
    {
        if (! $currentType instanceof UnionType) {
            return false;
        }

        if (! $inferedType instanceof UnionType) {
            return false;
        }

        return $inferedType->isSubTypeOf($currentType)->yes();
    }

    private function isVoidDueToThrow(Node $node, $inferredReturnNode): bool
    {
        if (! $inferredReturnNode instanceof Identifier) {
            return false;
        }

        if ($inferredReturnNode->name !== 'void') {
            return false;
        }

        return (bool) $this->betterNodeFinder->findFirstInstanceOf($node->stmts, Throw_::class);
    }
}
