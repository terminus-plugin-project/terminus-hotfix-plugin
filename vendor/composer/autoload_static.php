<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit2d7b2c9ca889db5358f8dfaa2a7db048
{
    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'Pantheon\\TerminusHotFix\\' => 24,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Pantheon\\TerminusHotFix\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit2d7b2c9ca889db5358f8dfaa2a7db048::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit2d7b2c9ca889db5358f8dfaa2a7db048::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
