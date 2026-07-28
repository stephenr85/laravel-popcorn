<?php

use Rushing\Popcorn\Runner\Build;
use Rushing\Popcorn\Runner\Exceptions\InvalidManifest;
use Rushing\Popcorn\Runner\GrantAxis;
use Rushing\Popcorn\Runner\Io;
use Rushing\Popcorn\Runner\Link;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;

it('builds from the load-bearing core alone, defaulting the optional publishing metadata', function () {
    $m = Manifest::fromArray([
        'name' => 'shape-payload',
        'runtime' => 'javy',
        'entrypoint' => 'transform.js',
    ]);

    expect($m->name)->toBe('shape-payload')
        ->and($m->runtime)->toBe('javy')
        ->and($m->entrypoint)->toBe('transform.js')
        ->and($m->files)->toBe([])
        ->and($m->io)->toBe(Io::Stdio)
        ->and($m->inputSchema)->toBeNull()
        ->and($m->outputSchema)->toBeNull()
        ->and($m->requests)->toBeNull()
        ->and($m->requestedGrant()->isFloor())->toBeTrue();
});

it('throws InvalidManifest when a required core field is missing', function () {
    expect(fn () => Manifest::fromArray(['name' => 'x', 'runtime' => 'node']))
        ->toThrow(InvalidManifest::class, 'entrypoint');
});

it('parses the substrate + publishing metadata including the requested grant', function () {
    $m = Manifest::fromArray([
        'name' => 'fetch-thing',
        'runtime' => 'node@22',
        'entrypoint' => 'index.js',
        'files' => ['index.js', 'lib/util.js'],
        'io' => 'files',
        'build' => 'source',
        'link' => 'static',
        'engineVersion' => 'javy-1.4',
        'input' => ['type' => 'object'],
        'output' => ['type' => 'object'],
        'requests' => [
            'net' => 'open',
            'paths' => ['ro' => ['/pkg'], 'rw' => ['/tmp']],
            'env' => ['API_BASE' => 'https://x'],
            'limits' => ['wallMs' => 5000, 'memBytes' => 134217728],
            'required' => ['net'],
        ],
    ]);

    expect($m->io)->toBe(Io::Files)
        ->and($m->build)->toBe(Build::Source)
        ->and($m->link)->toBe(Link::Static)
        ->and($m->engineVersion)->toBe('javy-1.4')
        ->and($m->files)->toBe(['index.js', 'lib/util.js'])
        ->and($m->requests->grant->net)->toBe(Net::Open)
        ->and($m->requests->grant->pathsRo)->toBe(['/pkg'])
        ->and($m->requests->grant->env)->toBe(['API_BASE' => 'https://x'])
        ->and($m->requests->grant->limits->wallMs)->toBe(5000)
        ->and($m->requests->isRequired(GrantAxis::Net))->toBeTrue()
        ->and($m->requests->isRequired(GrantAxis::Paths))->toBeFalse();
});

it('rejects an unknown enum value loudly', function () {
    expect(fn () => Manifest::fromArray([
        'name' => 'x', 'runtime' => 'node', 'entrypoint' => 'i.js', 'build' => 'nonsense',
    ]))->toThrow(InvalidManifest::class);
});

it('loads and validates a popcorn.json file', function () {
    $dir = sys_get_temp_dir().'/popcorn-manifest-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/popcorn.json', json_encode([
        'name' => 'from-file', 'runtime' => 'python@3.12', 'entrypoint' => 'main.py',
    ]));

    $m = Manifest::fromFile($dir.'/popcorn.json');

    expect($m->name)->toBe('from-file')->and($m->runtime)->toBe('python@3.12');

    unlink($dir.'/popcorn.json');
    rmdir($dir);
});

it('throws InvalidManifest for a missing or non-JSON file', function () {
    expect(fn () => Manifest::fromFile('/no/such/popcorn.json'))->toThrow(InvalidManifest::class);
});

it('treats the manifest file directory as the bundle root and joins relative entrypoints', function () {
    $dir = sys_get_temp_dir().'/popcorn-bundle-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/popcorn.json', json_encode([
        'name' => 'b', 'runtime' => 'node@22', 'entrypoint' => 'index.js',
    ]));

    $m = Manifest::fromFile($dir.'/popcorn.json');

    expect($m->bundleRoot)->toBe($dir)
        ->and($m->entrypointPath())->toBe($dir.'/index.js');

    // A host-staged Manifest stamps the root explicitly; an absolute entrypoint is left alone.
    $staged = Manifest::fromArray(['name' => 'b', 'runtime' => 'node', 'entrypoint' => 'e.js'])->withBundleRoot('/stage');
    expect($staged->entrypointPath())->toBe('/stage/e.js');

    unlink($dir.'/popcorn.json');
    rmdir($dir);
});
