<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\PhpGenerator;

use Nette;


/**
 * Creates a representation based on reflection.
 */
final class Factory
{
	use Nette\SmartObject;

	public function fromClassReflection(\ReflectionClass $from): ClassType
	{
		$class = $from->isAnonymous()
			? new ClassType
			: new ClassType($from->getShortName(), new PhpNamespace($from->getNamespaceName()));
		$class->setType($from->isInterface() ? $class::TYPE_INTERFACE : ($from->isTrait() ? $class::TYPE_TRAIT : $class::TYPE_CLASS));
		$class->setFinal($from->isFinal() && $class->isClass());
		$class->setAbstract($from->isAbstract() && $class->isClass());

		$ifaces = $from->getInterfaceNames();
		foreach ($ifaces as $iface) {
			$ifaces = array_filter($ifaces, function (string $item) use ($iface): bool {
				return !is_subclass_of($iface, $item);
			});
		}
		$class->setImplements($ifaces);

		$class->setComment(Helpers::unformatDocComment((string) $from->getDocComment()));
		if ($from->getParentClass()) {
			$class->setExtends($from->getParentClass()->getName());
			$class->setImplements(array_diff($class->getImplements(), $from->getParentClass()->getInterfaceNames()));
		}
		$props = $methods = $consts = [];
		foreach ($from->getProperties() as $prop) {
			if ($prop->isDefault() && $prop->getDeclaringClass()->getName() === $from->getName()) {
				$props[] = $this->fromPropertyReflection($prop);
			}
		}
		$class->setProperties($props);
		foreach ($from->getMethods() as $method) {
			if ($method->getDeclaringClass()->getName() === $from->getName()) {
				$methods[] = $this->fromMethodReflection($method);
			}
		}
		$class->setMethods($methods);

		foreach ($from->getReflectionConstants() as $const) {
			if ($const->getDeclaringClass()->name === $from->name) {
				$consts[] = $this->fromConstantReflection($const);
			}
		}
		$class->setConstants($consts);

		return $class;
	}


	public function fromMethodReflection(\ReflectionMethod $from): Method
	{
		$method = new Method($from->getName());
		$method->setParameters(array_map([$this, 'fromParameterReflection'], $from->getParameters()));
		$method->setStatic($from->isStatic());
		$isInterface = $from->getDeclaringClass()->isInterface();
		$method->setVisibility($from->isPrivate()
			? ClassType::VISIBILITY_PRIVATE
			: ($from->isProtected() ? ClassType::VISIBILITY_PROTECTED : ($isInterface ? null : ClassType::VISIBILITY_PUBLIC))
		);
		$method->setFinal($from->isFinal());
		$method->setAbstract($from->isAbstract() && !$isInterface);
		$method->setBody($from->isAbstract() ? null : '');
		$method->setReturnReference($from->returnsReference());
		$method->setVariadic($from->isVariadic());
		$method->setComment(Helpers::unformatDocComment((string) $from->getDocComment()));
		if ($from->getReturnType() instanceof \ReflectionNamedType) {
			$method->setReturnType($from->getReturnType()->getName());
			$method->setReturnNullable($from->getReturnType()->allowsNull());
		}
		return $method;
	}


	/** @return GlobalFunction|Closure */
	public function fromFunctionReflection(\ReflectionFunction $from)
	{
		$function = $from->isClosure() ? new Closure : new GlobalFunction($from->getName());
		$function->setParameters(array_map([$this, 'fromParameterReflection'], $from->getParameters()));
		$function->setReturnReference($from->returnsReference());
		$function->setVariadic($from->isVariadic());
		if (!$from->isClosure()) {
			$function->setComment(Helpers::unformatDocComment((string) $from->getDocComment()));
		}
		if ($from->getReturnType() instanceof \ReflectionNamedType) {
			$function->setReturnType($from->getReturnType()->getName());
			$function->setReturnNullable($from->getReturnType()->allowsNull());
		}
		return $function;
	}


	/** @return Method|GlobalFunction|Closure */
	public function fromCallable(callable $from)
	{
		$ref = Nette\Utils\Callback::toReflection($from);
		return $ref instanceof \ReflectionMethod
			? self::fromMethodReflection($ref)
			: self::fromFunctionReflection($ref);
	}


	public function fromParameterReflection(\ReflectionParameter $from): Parameter
	{
		$param = new Parameter($from->getName());
		$param->setReference($from->isPassedByReference());
		$param->setType($from->getType() instanceof \ReflectionNamedType ? $from->getType()->getName() : null);
		$param->setNullable($from->hasType() && $from->getType()->allowsNull());
		if ($from->isDefaultValueAvailable()) {
			$param->setDefaultValue($from->isDefaultValueConstant()
				? new Literal($from->getDefaultValueConstantName())
				: $from->getDefaultValue());
			$param->setNullable($param->isNullable() && $param->getDefaultValue() !== null);
		}
		return $param;
	}


	public function fromConstantReflection(\ReflectionClassConstant $from): Constant
	{
		$const = new Constant($from->name);
		$const->setValue($from->getValue());
		$const->setVisibility($from->isPrivate()
			? ClassType::VISIBILITY_PRIVATE
			: ($from->isProtected() ? ClassType::VISIBILITY_PROTECTED : ClassType::VISIBILITY_PUBLIC)
		);
		$const->setComment(Helpers::unformatDocComment((string) $from->getDocComment()));
		return $const;
	}


	public function fromPropertyReflection(\ReflectionProperty $from): Property
	{
		$defaults = $from->getDeclaringClass()->getDefaultProperties();
		$prop = new Property($from->getName());
		$prop->setValue($defaults[$prop->getName()] ?? null);
		$prop->setStatic($from->isStatic());
		$prop->setVisibility($from->isPrivate()
			? ClassType::VISIBILITY_PRIVATE
			: ($from->isProtected() ? ClassType::VISIBILITY_PROTECTED : ClassType::VISIBILITY_PUBLIC)
		);
		if (PHP_VERSION_ID >= 70400 && ($from->getType() instanceof \ReflectionNamedType)) {
			$prop->setType($from->getType()->getName());
			$prop->setNullable($from->getType()->allowsNull());
			$prop->setInitialized(array_key_exists($prop->getName(), $defaults));
		}
		$prop->setComment(Helpers::unformatDocComment((string) $from->getDocComment()));
		return $prop;
	}
}
