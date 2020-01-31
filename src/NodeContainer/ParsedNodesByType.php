<?php

declare(strict_types=1);

namespace Rector\NodeContainer;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;
use Rector\Exception\NotImplementedException;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\PhpParser\Node\Resolver\NameResolver;
use ReflectionClass;

/**
 * All parsed nodes grouped type
 */
final class ParsedNodesByType
{
    /**
     * @var string[]
     */
    private $collectableNodeTypes = [
        Class_::class,
        Interface_::class,
        ClassConst::class,
        ClassConstFetch::class,
        Trait_::class,
        ClassMethod::class,
        Function_::class,
        // simply collected
        New_::class,
        StaticCall::class,
        MethodCall::class,
        // for array callable - [$this, 'someCall']
        Array_::class,
        // for unused classes
        Param::class,
    ];

    /**
     * @var Class_[]
     */
    private $classes = [];

    /**
     * @var NameResolver
     */
    private $nameResolver;

    /**
     * @var ClassConst[][]
     */
    private $constantsByType = [];

    /**
     * @var NodeTypeResolver
     */
    private $nodeTypeResolver;

    /**
     * @var ClassMethod[][]
     */
    private $methodsByType = [];

    /**
     * @var Node[][]
     */
    private $simpleParsedNodesByType = [];

    /**
     * @var MethodCall[][][]|StaticCall[][][]
     */
    private $methodsCallsByTypeAndMethod = [];

    /**
     * E.g. [$this, 'someLocalMethod']
     * @var Array_[][][]
     */
    private $arrayCallablesByTypeAndMethod = [];

    public function __construct(NameResolver $nameResolver)
    {
        $this->nameResolver = $nameResolver;
    }

    /**
     * @return Node[]
     */
    public function getNodesByType(string $type): array
    {
        return $this->simpleParsedNodesByType[$type] ?? [];
    }

    /**
     * @return Interface_[]
     */
    public function getInterfaces(): array
    {
        return $this->simpleParsedNodesByType[Interface_::class] ?? [];
    }

    /**
     * @return Class_[]
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    /**
     * To prevent circular reference
     * @required
     */
    public function autowireParsedNodesByType(NodeTypeResolver $nodeTypeResolver): void
    {
        $this->nodeTypeResolver = $nodeTypeResolver;
    }

    public function findClass(string $name): ?Class_
    {
        return $this->classes[$name] ?? null;
    }

    public function findInterface(string $name): ?Interface_
    {
        return $this->simpleParsedNodesByType[Interface_::class][$name] ?? null;
    }

    public function findTrait(string $name): ?Trait_
    {
        return $this->simpleParsedNodesByType[Trait_::class][$name] ?? null;
    }

    /**
     * Guessing the nearest neighboor.
     * Used e.g. for "XController"
     */
    public function findByShortName(string $shortName): ?Class_
    {
        foreach ($this->classes as $className => $classNode) {
            if (Strings::endsWith($className, '\\' . $shortName)) {
                return $classNode;
            }
        }

        return null;
    }

    /**
     * @return Class_|Interface_|null
     */
    public function findClassOrInterface(string $type): ?ClassLike
    {
        $class = $this->findClass($type);
        if ($class !== null) {
            return $class;
        }

        return $this->findInterface($type);
    }

    public function findClassConstant(string $className, string $constantName): ?ClassConst
    {
        if (Strings::contains($constantName, '\\')) {
            throw new ShouldNotHappenException(sprintf('Switched arguments in "%s"', __METHOD__));
        }

        return $this->constantsByType[$className][$constantName] ?? null;
    }

    public function findFunction(string $name): ?Function_
    {
        return $this->simpleParsedNodesByType[Function_::class][$name] ?? null;
    }

    public function findClassMethodByMethodCall(MethodCall $methodCall): ?ClassMethod
    {
        /** @var string|null $className */
        $className = $methodCall->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) {
            return null;
        }

        $methodName = $this->nameResolver->getName($methodCall->name);
        if ($methodName === null) {
            return null;
        }

        return $this->findMethod($methodName, $className);
    }

    public function findClassMethodByStaticCall(StaticCall $staticCall): ?ClassMethod
    {
        $methodName = $this->nameResolver->getName($staticCall->name);
        if ($methodName === null) {
            return null;
        }

        $objectType = $this->nodeTypeResolver->resolve($staticCall->class);

        $classNames = TypeUtils::getDirectClassNames($objectType);
        foreach ($classNames as $className) {
            $foundMethod = $this->findMethod($methodName, $className);
            if ($foundMethod !== null) {
                return $foundMethod;
            }
        }

        return null;
    }

    public function findMethod(string $methodName, string $className): ?ClassMethod
    {
        if (isset($this->methodsByType[$className][$methodName])) {
            return $this->methodsByType[$className][$methodName];
        }

        $parentClass = $className;
        while ($parentClass = get_parent_class($parentClass)) {
            if (isset($this->methodsByType[$parentClass][$methodName])) {
                return $this->methodsByType[$parentClass][$methodName];
            }
        }

        return null;
    }

    public function isStaticMethod(string $methodName, string $className): bool
    {
        $methodNode = $this->findMethod($methodName, $className);
        if ($methodNode !== null) {
            return $methodNode->isStatic();
        }

        // could be static in doc type magic
        // @see https://regex101.com/r/tlvfTB/1
        if (class_exists($className) || trait_exists($className)) {
            $reflectionClass = new ReflectionClass($className);
            if (Strings::match(
                (string) $reflectionClass->getDocComment(),
                '#@method\s*static\s*(.*?)\b' . $methodName . '\b#'
            )) {
                return true;
            }

            // probably magic method → we don't know
            if (! method_exists($className, $methodName)) {
                return false;
            }

            $methodReflection = $reflectionClass->getMethod($methodName);
            return $methodReflection->isStatic();
        }

        return false;
    }

    public function isCollectableNode(Node $node): bool
    {
        foreach ($this->collectableNodeTypes as $collectableNodeType) {
            if (is_a($node, $collectableNodeType, true)) {
                return true;
            }
        }

        return false;
    }

    public function collect(Node $node): void
    {
        $nodeClass = get_class($node);

        if ($node instanceof Class_) {
            $this->addClass($node);
            return;
        }

        if ($node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Function_) {
            $name = $this->nameResolver->getName($node);
            if ($name === null) {
                throw new ShouldNotHappenException();
            }

            $this->simpleParsedNodesByType[$nodeClass][$name] = $node;
            return;
        }

        if ($node instanceof ClassConst) {
            $this->addClassConstant($node);
            return;
        }

        if ($node instanceof ClassMethod) {
            $this->addMethod($node);
            return;
        }

        // array callable - [$this, 'someCall']
        if ($node instanceof Array_) {
            $arrayCallableClassAndMethod = $this->matchArrayCallableClassAndMethod($node);
            if ($arrayCallableClassAndMethod === null) {
                return;
            }

            [$className, $methodName] = $arrayCallableClassAndMethod;
            if (! method_exists($className, $methodName)) {
                return;
            }

            $this->arrayCallablesByTypeAndMethod[$className][$methodName][] = $node;
            return;
        }

        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            $this->addCall($node);
        }

        // simple collect
        $this->simpleParsedNodesByType[$nodeClass][] = $node;
    }

    /**
     * @return MethodCall[]|StaticCall[]|Array_[]
     */
    public function findClassMethodCalls(ClassMethod $classMethod): array
    {
        $className = $classMethod->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) { // anonymous
            return [];
        }

        $methodName = $this->nameResolver->getName($classMethod);
        if ($methodName === null) {
            return [];
        }

        return $this->methodsCallsByTypeAndMethod[$className][$methodName] ?? $this->arrayCallablesByTypeAndMethod[$className][$methodName] ?? [];
    }

    /**
     * @return MethodCall[][]|StaticCall[][]
     */
    public function findMethodCallsOnClass(string $className): array
    {
        return $this->methodsCallsByTypeAndMethod[$className] ?? [];
    }

    /**
     * @return New_[]
     */
    public function findNewNodesByClass(string $className): array
    {
        $newNodesByClass = [];

        foreach ($this->getNodesByType(New_::class) as $newNode) {
            if (! $this->nameResolver->isName($newNode->class, $className)) {
                continue;
            }

            /** @var New_ $newNode */
            $newNodesByClass[] = $newNode;
        }

        return $newNodesByClass;
    }

    public function findClassConstantByClassConstFetch(ClassConstFetch $classConstFetch): ?ClassConst
    {
        $class = $this->nameResolver->getName($classConstFetch->class);

        if ($class === 'self') {
            /** @var string|null $class */
            $class = $classConstFetch->getAttribute(AttributeKey::CLASS_NAME);
        } elseif ($class === 'parent') {
            /** @var string|null $class */
            $class = $classConstFetch->getAttribute(AttributeKey::PARENT_CLASS_NAME);
        }

        if ($class === null) {
            throw new NotImplementedException();
        }

        /** @var string $constantName */
        $constantName = $this->nameResolver->getName($classConstFetch->name);

        return $this->findClassConstant($class, $constantName);
    }

    private function addClass(Class_ $classNode): void
    {
        if ($this->isClassAnonymous($classNode)) {
            return;
        }

        $className = $classNode->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) {
            throw new ShouldNotHappenException();
        }

        $this->classes[$className] = $classNode;
    }

    private function addClassConstant(ClassConst $classConst): void
    {
        $className = $classConst->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) {
            // anonymous class constant
            return;
        }

        $constantName = $this->nameResolver->getName($classConst);

        $this->constantsByType[$className][$constantName] = $classConst;
    }

    private function addMethod(ClassMethod $classMethod): void
    {
        $className = $classMethod->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) { // anonymous
            return;
        }

        $methodName = $this->nameResolver->getName($classMethod);
        $this->methodsByType[$className][$methodName] = $classMethod;
    }

    /**
     * Matches array like: "[$this, 'methodName']" → ['ClassName', 'methodName']
     * @return string[]|null
     */
    private function matchArrayCallableClassAndMethod(Array_ $array): ?array
    {
        if (count($array->items) !== 2) {
            return null;
        }

        if ($array->items[0] === null) {
            return null;
        }

        // $this, self, static, FQN
        if (! $this->isThisVariable($array->items[0]->value)) {
            return null;
        }

        if ($array->items[1] === null) {
            return null;
        }

        if (! $array->items[1]->value instanceof String_) {
            return null;
        }

        /** @var String_ $string */
        $string = $array->items[1]->value;

        $methodName = $string->value;
        $className = $array->getAttribute(AttributeKey::CLASS_NAME);

        if ($className === null) {
            return null;
        }

        return [$className, $methodName];
    }

    /**
     * @param MethodCall|StaticCall $node
     */
    private function addCall(Node $node): void
    {
        // one node can be of multiple-class types
        if ($node instanceof MethodCall) {
            if ($node->var instanceof MethodCall) {
                $classType = $this->resolveNodeClassTypes($node);
            } else {
                $classType = $this->resolveNodeClassTypes($node->var);
            }
        } else {
            $classType = $this->resolveNodeClassTypes($node->class);
        }

        $methodName = $this->nameResolver->getName($node);
        if ($classType instanceof MixedType) { // anonymous
            return;
        }

        if ($methodName === null) {
            return;
        }

        if ($classType instanceof ObjectType) {
            $this->methodsCallsByTypeAndMethod[$classType->getClassName()][$methodName][] = $node;
        }

        if ($classType instanceof UnionType) {
            foreach ($classType->getTypes() as $unionedType) {
                if (! $unionedType instanceof ObjectType) {
                    continue;
                }

                $this->methodsCallsByTypeAndMethod[$unionedType->getClassName()][$methodName][] = $node;
            }
        }
    }

    private function isClassAnonymous(Class_ $classNode): bool
    {
        if ($classNode->isAnonymous() || $classNode->name === null) {
            return true;
        }

        // PHPStan polution
        return Strings::startsWith($classNode->name->toString(), 'AnonymousClass');
    }

    private function isThisVariable(Node $node): bool
    {
        // $this
        if ($node instanceof Variable && $this->nameResolver->isName($node, 'this')) {
            return true;
        }

        if ($node instanceof ClassConstFetch) {
            if (! $this->nameResolver->isName($node->name, 'class')) {
                return false;
            }

            // self::class, static::class
            if ($this->nameResolver->isNames($node->class, ['self', 'static'])) {
                return true;
            }

            /** @var string|null $className */
            $className = $node->getAttribute(AttributeKey::CLASS_NAME);

            if ($className === null) {
                return false;
            }

            return $this->nameResolver->isName($node->class, $className);
        }

        return false;
    }

    private function resolveNodeClassTypes(Node $node): Type
    {
        if ($node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === 'this') {
            /** @var string|null $className */
            $className = $node->getAttribute(AttributeKey::CLASS_NAME);

            if ($className) {
                return new ObjectType($className);
            }

            return new MixedType();
        }

        if ($node instanceof MethodCall) {
            return $this->nodeTypeResolver->resolve($node->var);
        }

        return $this->nodeTypeResolver->resolve($node);
    }
}
