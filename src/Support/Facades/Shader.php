<?php

declare(strict_types=1);

namespace PHPolygon\Support\Facades;

/**
 * @method static void register(string $id, \PHPolygon\Rendering\ShaderDefinition $definition)
 * @method static bool has(string $id)
 * @method static \PHPolygon\Rendering\ShaderDefinition|null get(string $id)
 * @method static string[] available()
 * @method static void use(string $shaderId)
 * @method static void reset()
 * @method static string|null active()
 * @method static bool isOverridden()
 *
 * @see \PHPolygon\Rendering\ShaderManager
 */
class Shader extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'shaders';
    }
}
