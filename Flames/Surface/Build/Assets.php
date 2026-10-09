<?php

declare(strict_types=1);

namespace Flames\Surface\Build;

use Flames\Collection\Arr;
use Flames\Forge\Cli;
use Flames\Surface\Build\Assets\Automate;
use Flames\Surface\Build\Assets\Data;
use Flames\Env\Env;
use Flames;
use Flames\Kernel;

/**
 * Builds the client-side JavaScript bundle (Flames.js) for Surface runtime.
 *
 * @internal
 */
final class Assets
{
    public const string BASE_PATH = APP_PATH . 'Client/Resource/Build/';

    public const string PUBLIC_APP_PATH = ROOT_PATH . 'public/&flames/app.js';

    /** Minimal client runtime seeds — expanded at build time via {@see Assets\Dependencies}. */
    /** @var list<class-string> */
    private const array CORE_SEEDS = [
        \App\App::class,
        \Flames\Surface\Runtime\Error::class,
        \Flames\Surface\Runtime\KernelClient::class,
        \Flames\Surface\Runtime\Virtual::class,
        \Flames\Surface\Runtime\AutoLoad::class,
        Flames\Connection\Client::class,
        Flames\Collection\Strings::class,
        Flames\Collection\Bools::class,
        Flames\Collection\Ints::class,
        Flames\Collection\Floats::class,
        Flames\Collection\Arr::class,
        Flames\Collection\Functions::class,
        Flames\Php::class,
        Flames\Js::class,
        Flames\Js\Window::class,
        \Flames\Surface\Surface\Js\Bridge::class,
        Flames\Forge\Cli::class,
        Flames\Framework\Controller\RequestData::class,
        Flames\Kernel\Route::class,
        Flames\Browser\Page::class,
        Flames\Router::class,
        Flames\Json::class,
        Flames\Event\Route::class,
        Flames\Event\Ready::class,
        Flames\Event\Page::class,
        Flames\Event\Native::class,
        Flames\Router\Parser::class,
        Flames\Header\Client::class,
        Flames\Coroutine\Timeout::class,
        Flames\Element::class,
        Flames\Element\Event::class,
        Flames\Money\Client::class,
        Flames\Event\Element\Click::class,
        Flames\Event\Element\Change::class,
        Flames\Event\Element\Input::class,
        Flames\Event\Element\KeyDown::class,
        Flames\Event\Element\KeyUp::class,
        Flames\Event\Element\Focus::class,
        \Flames\Surface\Runtime\Dispatch::class,
        Flames\Js\Module::class,
        Flames\Client\Os::class,
        Flames\Client\Platform::class,
        Flames\Client\Browser::class,
        Flames\Client\UserAgentParser::class,
        \Flames\Surface\Runtime\Dispatch\Tag::class,
        Flames\Client\Tag::class,
        Flames\Element\Shadow::class,
        \Flames\Surface\Runtime\Service\Keyboard::class,
        \Flames\Surface\Runtime\Service\Clipboard::class,
        Flames\Client\Keyboard::class,
        Flames\Client\Keyboard\Event::class,
        Flames\Client\Clipboard::class,
        Flames\Client\Clipboard\Event::class,
        Flames\Event\Clipboard\Paste::class,
        Flames\FunctionEx::class,
        Flames\Cache\Memory\Client::class,
        Flames\Cookie\Client::class,
        Flames\Date\DateTime::class,
        Flames\Date\TimeZone\Client::class,
        \Flames\Surface\Runtime\Dispatch\Native::class,
        Flames\Client\Native::class,
        Flames\Client\Browser\DevTools::class,
        Flames\Client\Shell::class,
        Flames\Event\Native\Shell::class,
        Flames\Env\Env::class,
        Flames\Ready\ResetData::class,
    ];

    /** Classes that must be eval'd in order before the WASM autorun (needs Js + Virtual + AutoLoad). */
    /** @var list<class-string> */
    private const array AUTORUN_BOOTSTRAP_SEEDS = [
        \App\App::class,
        Flames\Js\Window::class,
        \Flames\Surface\Surface\Js\Bridge::class,
        Flames\Js::class,
        \Flames\Surface\Runtime\Error::class,
        \Flames\Surface\Runtime\Virtual::class,
        \Flames\Surface\Runtime\AutoLoad::class,
    ];

    /** @var list<class-string> */
    private const array CLIENT_MOCKS = [
        Flames\Kernel\Client::class,
        Flames\Connection\Client::class,
        Flames\Header\Client::class,
        Flames\Money\Client::class,
        Flames\Cache\Memory\Client::class,
        Flames\Cookie\Client::class,
        Flames\Date\TimeZone\Client::class,
    ];

    private bool $debug = false;
    private bool $auto  = false;
    private bool $swfExtension = false;

    /** Cached exploded CLIENT_EXTENSIONS list (null = not yet resolved). */
    private ?array $clientExtensions = null;

    /** @var array<class-string, list<class-string>> */
    private array $dependencyMap = [];

    public function __construct(mixed $data)
    {
        $this->auto = (bool)($data->option->contains('auto') ?? false);
    }

    public function run(bool $debug = false): bool
    {
        if ($this->auto && !Cli::isCli() && (($_GET['timeout'] ?? '') === 'true')) {
            $this->verifyAuto();
            return false;
        }

        $this->debug = $debug;
        $this->ensureFolder();

        $stream = $this->openStream();

        $this->injectStructure($stream);
        $this->injectExtensions($stream);
        $this->injectDefaultFiles($stream);
        $this->finish($stream);
        $this->verifyAuto();

        return true;
    }

    /** @return resource */
    private function openStream(): mixed
    {
        $path   = self::BASE_PATH . 'Flames.js';
        $stream = @fopen($path, 'w');

        if ($stream === false) {
            if (!is_dir(self::BASE_PATH)) {
                $mask = umask(0);
                mkdir(self::BASE_PATH, 0777, true);
                umask($mask);
            }
            $stream = fopen($path, 'w');
        }

        if ($stream === false) {
            throw new \RuntimeException('Cannot write Surface bundle to ' . $path);
        }

        return $stream;
    }

    private function ensureFolder(): void
    {
        if ($this->debug) {
            echo 'Verifying base resource folder ' . substr(self::BASE_PATH, strlen(ROOT_PATH)) . "\n";
        }

        if (!is_dir(self::BASE_PATH)) {
            if ($this->debug) {
                echo 'Creating base resource folder ' . self::BASE_PATH . "\n";
            }
            $mask = umask(0);
            mkdir(self::BASE_PATH, 0777, true);
            umask($mask);
        }
    }

    /** @param resource $stream */
    private function injectStructure(mixed $stream): void
    {
        if ($this->debug) {
            echo "Inject Surface bootstrap\n";
        }

        $this->swfExtension     = false;
        $this->clientExtensions = null;
        $raw                    = Env::get('CLIENT_EXTENSIONS');

        if ($raw !== null) {
            $this->clientExtensions = explode(',', strtolower((string)$raw));
            $this->swfExtension     = in_array('swf', $this->clientExtensions, true);
        }

        $this->writeSurfaceBootstrap($stream);
        fwrite($stream, 'Flames.Surface.onLoad=async function(){');
    }

    /** @param resource $stream */
    private function injectExtensions(mixed $stream): void
    {
        if ($this->debug) {
            echo "Inject default loaded extensions\n";
        }

        $extensions = $this->clientExtensions;
        if ($extensions === null) {
            return;
        }

        $eval = '';

        foreach ($extensions as $extension) {
            if ($extension === 'swf') {
                continue;
            }
            if ($eval !== '') {
                $eval .= 'usleep(1);';
            }
            $eval .= "dl('{$extension}.so');";
        }

        if ($eval !== '') {
            fwrite($stream, $this->jsEvalBase64(base64_encode($eval)));
        }

        if ($this->swfExtension) {
            fwrite($stream, "
                var xmlhttp = new XMLHttpRequest();
                xmlhttp.open('GET', 'https://cdn.jsdelivr.net/gh/flamesphp/cdn@" . Kernel::CDN_VERSION . "/swf/swf.js');
                xmlhttp.onreadystatechange = function() { if ((xmlhttp.status == 200) && (xmlhttp.readyState == 4)) { eval(xmlhttp.responseText); }};
                xmlhttp.send();
            ");
        }
    }

    private function loadPhpFile(string $path): string
    {
        $content = str_replace('<?php', '', (string) @file_get_contents($path));

        // Dumpper/debug attributes break php-wasm eval and are not needed client-side.
        $content = preg_replace('/^\s*#\[[^\]]+\]\s*\r?\n/m', '', $content) ?? $content;

        return $content;
    }

    private function resolveClassFile(string $class): ?string
    {
        if ($class === 'App\\App') {
            $path = FLAMES_PATH . 'framework/App.php';
            return is_file($path) ? $path : null;
        }

        if (str_starts_with($class, 'Flames\\')) {
            $parts = explode('\\', $class);
            $packagePath = FLAMES_PATH . strtolower($parts[1]) . '/'
                . str_replace('\\', '/', $class) . '.php';
            if (is_file($packagePath)) {
                return $packagePath;
            }
        }

        $relative = substr(str_replace('\\', '/', $class), 7) . '.php';
        $deprecated = FLAMES_PATH . 'framework/Flames/(deprecated)/' . $relative;
        if (is_file($deprecated) && !str_starts_with($class, 'Flames\\Kernel\\Client\\')) {
            return $deprecated;
        }

        $framework = FLAMES_PATH . 'framework/Flames/' . $relative;
        if (is_file($framework)) {
            return $framework;
        }

        $flat = FLAMES_PATH . $relative;
        return is_file($flat) ? $flat : null;
    }

    private function loadClassPhp(string $class): string
    {
        $path = $this->resolveClassFile($class);
        if ($path === null) {
            throw new \RuntimeException('Missing client class file: ' . $class);
        }

        return $this->loadPhpFile($path);
    }

    private function loadOptionalClassPhp(string $class): string
    {
        $path = $this->resolveClassFile($class);
        if ($path === null) {
            return '';
        }

        return $this->loadPhpFile($path);
    }

    private function shouldTransformMock(string $fullClass, string $phpFile): bool
    {
        $split = explode('\\', $fullClass);
        if (count($split) < 2) {
            return false;
        }

        $parentNamespace = implode('\\', array_slice($split, 0, -1));

        // PSR-4 client stubs already live in the parent namespace (e.g. Flames\Kernel\Client).
        if (preg_match('/^\s*namespace\s+' . preg_quote($parentNamespace, '/') . '\s*;/m', $phpFile) === 1) {
            return false;
        }

        return true;
    }

    private function parseMockFile(string $fullClass, string $data): string
    {
        $split      = explode('\\', $fullClass);
        $splitCount = count($split);

        $oldNamespace = implode('\\', array_slice($split, 0, $splitCount - 1));
        $newNamespace = implode('\\', array_slice($split, 0, $splitCount - 2));
        $oldClass     = $split[$splitCount - 1];
        $newClass     = $split[$splitCount - 2];

        return str_replace(
            ['namespace ' . $oldNamespace, 'class ' . $oldClass],
            ['namespace ' . $newNamespace, 'class ' . $newClass],
            $data
        );
    }

    /** @param resource $stream */
    private function injectDefaultFiles(mixed $stream): void
    {
        /** @var array<string, string> $buffers */
        $buffers = [];

        $this->mountVirtualDefaultFiles($buffers);
        $this->appendOptionalRuntimeClass($buffers, \Flames\Dump\Client::class);
        $clientMetadata = $this->mountVirtualClientFilesMetadata($buffers);

        $tagsMap = [];
        foreach ($clientMetadata->tags as $tag) {
            $tagsMap[$tag->uid] = $tag->class;
        }

        $viewsMap = [];
        if (Assets\Mesh::isMeshExtension()) {
            foreach ($clientMetadata->views as $viewNs => $viewData) {
                $viewsMap[$viewNs] = base64_encode((string) $viewData);
            }
        }

        $bootstrapChain = $this->buildBootstrapChain();
        $bootScriptB64  = base64_encode($this->buildWasmBootScript($buffers, $bootstrapChain));

        $manifest = [
            'buffers'      => $buffers,
            'dependencies' => $this->buildDependenciesArray(),
            'constructors' => $clientMetadata->staticConstructors->toArray(),
            'tags'         => $tagsMap,
            'views'        => $viewsMap,
            'events'       => $clientMetadata->events->toArray(),
            'publicEnv'    => PublicEnv::collect(),
        ];

        $payloadJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw new \RuntimeException('Failed to encode Surface payload manifest.');
        }

        $this->writeSurfaceBootScript($stream, base64_encode($payloadJson), $bootScriptB64);

        fwrite($stream, 'await Flames.Surface.boot();');

        foreach ($clientMetadata->tags as $tag) {
            fwrite($stream, "window.eval(atob('" . base64_encode($tag->eval) . "'));");
        }

        fwrite($stream, 'if(typeof Flames.Internal.runAutoBuildWatcher==="function"){Flames.Internal.runAutoBuildWatcher();}');
        fwrite($stream, '};');
    }

    /**
     * @return array<string, list<string>>
     */
    private function buildDependenciesArray(): array
    {
        $map = [];

        foreach ($this->dependencyMap as $class => $dependencies) {
            if ($dependencies === [] || !$this->isBundleClassName($class)) {
                continue;
            }

            $deps = array_values(array_unique(array_filter(
                $dependencies,
                $this->isBundleClassName(...),
            )));

            if ($deps !== []) {
                $map[$class] = $deps;
            }
        }

        return $map;
    }

    /**
     * Classes that must exist before the WASM autorun executes (hash + fqcn for idempotent eval).
     *
     * @return list<array{hash: string, class: string}>
     */
    private function buildBootstrapChain(): array
    {
        $resolver = new Assets\Dependencies(fn (string $class): ?string => $this->resolveClassFile($class));
        $order    = $resolver->resolve(self::AUTORUN_BOOTSTRAP_SEEDS);
        $chain    = [];
        $seen     = [];

        foreach ($order as $class) {
            if (!$this->isBundleClassName($class)) {
                continue;
            }

            $hash = sha1($class);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $chain[]     = ['hash' => $hash, 'class' => $class];
        }

        return $chain;
    }

    /**
     * Single php-wasm script: bootstrap seeds + Virtual hydrate + Dispatch (one php.run()).
     *
     * @param array<string, string>              $buffers
     * @param list<array{hash: string, class: string}> $bootstrapChain
     */
    private function buildWasmBootScript(array $buffers, array $bootstrapChain): string
    {
        $php = "if(!defined('MODULE')){define('MODULE','CLIENT');}\n";

        foreach ($bootstrapChain as $entry) {
            $class  = $entry['class'];
            $source = $buffers[$entry['hash']] ?? '';
            if ($source === '') {
                continue;
            }

            // Namespace declarations must not sit inside if-blocks; eval() parses each unit separately.
            $php .= 'if(!class_exists(' . var_export($class, true) . ',false)';
            $php .= '&&!interface_exists(' . var_export($class, true) . ',false)';
            $php .= '&&!trait_exists(' . var_export($class, true) . ',false)){';
            $php .= 'eval(' . var_export(trim($source), true) . ');';
            $php .= "}\n";
        }

        $php .= <<<'PHP'
$payload = \Flames\Js::getWindow()->Flames->Internal->payload;
\Flames\Surface\Runtime\Virtual::bootstrap($payload);
\Flames\Surface\Runtime\AutoLoad::run();
function Arr(mixed $value=null):\Flames\Collection\Arr{if($value instanceof \Flames\Collection\Arr){return $value;}return new \Flames\Collection\Arr($value);}
function once(\Closure $delegate):mixed{return \Flames\Collection\Functions::once($delegate);}
if(isset(\Flames\Js::getWindow()->Flames->Internal->publicEnv)){\Flames\Ready\ResetData::$data[".env"]=(array)\Flames\Js::getWindow()->Flames->Internal->publicEnv;\Flames\Ready\ResetData::$data[".env.public"]=array_fill_keys(array_keys(\Flames\Ready\ResetData::$data[".env"]),true);}
\Flames\Surface\Runtime\Dispatch::run();

PHP;

        return $php;
    }

    /** @param resource $stream */
    private function writeSurfaceBootScript(mixed $stream, string $payloadB64, string $bootScriptB64): void
    {
        fwrite($stream, 'Flames.Surface._payloadB64="' . $payloadB64 . '";');
        fwrite($stream, 'Flames.Surface._bootScriptB64="' . $bootScriptB64 . '";');
        fwrite($stream, 'Flames.Surface.boot=async function(){');
        fwrite($stream, 'var manifest=JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(Flames.Surface._payloadB64),function(c){return c.charCodeAt(0);})));');
        fwrite($stream, 'window.Flames.Internal.payload=manifest;');
        fwrite($stream, 'window.Flames.Internal.eventTriggers=manifest.events||[];');
        fwrite($stream, 'window.Flames.Internal.publicEnv=manifest.publicEnv||{};');
        fwrite($stream, 'var tz=manifest.publicEnv&&manifest.publicEnv.DATE_TIMEZONE;');
        fwrite($stream, 'if(typeof tz==="string"&&tz!==""){window.Flames.Internal.dateTimeZone=tz;}');
        fwrite($stream, 'await Flames.Internal.evalBase64(Flames.Surface._bootScriptB64);};');
    }

    /**
     * @param array<string, string> $buffers
     */
    private function mountVirtualDefaultFiles(array &$buffers): void
    {
        $resolver = new Assets\Dependencies(fn (string $class): ?string => $this->resolveClassFile($class));
        $seeds    = $this->collectRuntimeSeeds();
        $resolved = $resolver->resolve($seeds);
        $this->dependencyMap = $resolver->dependencyMap($seeds);

        $clientMocks    = self::CLIENT_MOCKS;
        if (Assets\Mesh::isMeshExtension()) {
            $clientMocks = Assets\Mesh::injectClientMocks($clientMocks);
        }
        $clientMocksSet = array_flip($clientMocks);

        $buffered = [];

        foreach ($resolved as $defaultFile) {
            if (str_starts_with($defaultFile, 'App\\Client\\')) {
                continue;
            }

            $this->appendVirtualClassBuffer(
                $buffers,
                $defaultFile,
                $clientMocksSet,
                $buffered,
            );
        }

        $this->ensureDependencyBuffers(
            $buffers,
            $clientMocksSet,
            $buffered,
        );
    }

    /**
     * @return list<class-string>
     */
    private function collectRuntimeSeeds(): array
    {
        $seeds = self::CORE_SEEDS;

        $seeds = array_merge($seeds, Assets\Dependencies::scanClientClasses());
        $seeds = array_merge($seeds, Assets\Dependencies::scanClientReferences());

        if (Assets\Mesh::isMeshExtension()) {
            $seeds = Assets\Mesh::injectDefaultFiles($seeds);
        }

        if (Assets\Http::shouldBundle()) {
            $seeds = array_merge($seeds, Assets\Http::defaultFiles());
        }

        return array_values(array_unique($seeds));
    }

    /**
     * @param array<string, string>    $buffers
     * @param array<class-string, int> $clientMocksSet
     * @param array<string, true>      $buffered
     */
    private function appendVirtualClassBuffer(
        array &$buffers,
        string $defaultFile,
        array $clientMocksSet,
        array &$buffered = [],
    ): void {
        $bufferKey = $this->virtualBufferKey($defaultFile, $clientMocksSet);
        if (isset($buffered[$bufferKey])) {
            return;
        }

        $path = $this->resolveClassFile($defaultFile);
        if ($path === null) {
            if ($this->debug) {
                echo 'Skip missing runtime class ' . $defaultFile . "\n";
            }

            return;
        }

        $phpFile = $this->loadPhpFile($path);

        if (isset($clientMocksSet[$defaultFile])) {
            if ($this->shouldTransformMock($defaultFile, $phpFile)) {
                $phpFile = $this->parseMockFile($defaultFile, $phpFile);
            }
            $split       = explode('\\', $defaultFile);
            $defaultFile = implode('\\', array_slice($split, 0, count($split) - 1));
        }

        if ($this->debug) {
            echo 'Compile ' . $defaultFile . ".php\n";
        }

        $buffered[$bufferKey]   = true;
        $buffers[sha1($bufferKey)] = $phpFile;
    }

    /**
     * @param array<string, string>    $buffers
     * @param array<class-string, int> $clientMocksSet
     * @param array<string, true>      $buffered
     */
    private function ensureDependencyBuffers(
        array &$buffers,
        array $clientMocksSet,
        array &$buffered,
    ): void {
        foreach ($this->dependencyMap as $dependencies) {
            foreach ($dependencies as $dependency) {
                if (!$this->isBundleClassName($dependency)) {
                    continue;
                }

                $this->appendVirtualClassBuffer(
                    $buffers,
                    $dependency,
                    $clientMocksSet,
                    $buffered,
                );
            }
        }
    }

    /**
     * @param array<class-string, int> $clientMocksSet
     */
    private function virtualBufferKey(string $class, array $clientMocksSet): string
    {
        if (!isset($clientMocksSet[$class])) {
            return $class;
        }

        $split = explode('\\', $class);

        return implode('\\', array_slice($split, 0, count($split) - 1));
    }

    private function isBundleClassName(string $class): bool
    {
        return (bool) preg_match('/^(Flames|App)(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $class);
    }

    /**
     * Compiles every .php under App/Client/ into the Surface bundle.
     * Only App/Client/Resource/Build/ is excluded (generated output).
     */
    /**
     * @param array<string, string> $buffers
     */
    private function mountVirtualClientFilesMetadata(array &$buffers): mixed
    {
        $useViews = Assets\Mesh::isMeshExtension();

        $staticConstructors = new Arr();
        $events             = new Arr();
        $tags               = new Arr();
        $views              = new Arr();

        $rootPathLen  = strlen(ROOT_PATH);
        $clientPath   = APP_PATH . 'Client/';
        $clientPrefix = strlen($clientPath);

        if (!is_dir($clientPath)) {
            $data                     = new Arr();
            $data->staticConstructors = $staticConstructors;
            $data->events             = $events;
            $data->tags               = $tags;
            $data->views              = $views;

            return $data;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($clientPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $file = $item->getPathname();

            if ($this->isClientSourceExcluded($file)) {
                continue;
            }

            $ext = strtolower('.' . $item->getExtension());

            if (Assets\Mesh::isClientViewFile($file)) {
                if (!$useViews) {
                    continue;
                }
                $fileData = (string)file_get_contents($file);
                if (!Assets\Mesh::shouldBundleClientView($file, $fileData)) {
                    continue;
                }
                $fileData       = (string)preg_replace('/ {2,}/', ' ', $fileData);
                $viewNs         = str_replace('\\', '/', substr($file, $clientPrefix));
                $views[$viewNs] = $fileData;
                continue;
            }

            if ($ext !== '.php') {
                continue;
            }

            $class = str_replace('/', '\\', substr($file, $rootPathLen, -4));

            $this->collectClientClassMetadata($class, $events, $staticConstructors, $tags);

            if ($this->debug) {
                echo 'Compile client: ' . $class . "\n";
            }

            $phpFile                  = $this->loadPhpFile($file);
            $buffers[sha1($class)] = $phpFile;
        }

        $data                     = new Arr();
        $data->staticConstructors = $staticConstructors;
        $data->events             = $events;
        $data->tags               = $tags;
        $data->views              = $views;

        return $data;
    }

    /** @return list<string> */
    private function clientSourceExcludeDirs(): array
    {
        return [
            APP_PATH . 'Client/Resource/Build/',
        ];
    }

    private function isClientSourceExcluded(string $path): bool
    {
        return array_any($this->clientSourceExcludeDirs(), fn($exclude) => str_starts_with($path, $exclude));
    }

    /** @param Arr $events */
    /** @param Arr $staticConstructors */
    /** @param Arr $tags */
    private function collectClientClassMetadata(string $class, mixed $events, mixed $staticConstructors, mixed $tags): void
    {
        try {
            $data  = Data::mountData($class);
            $attrs = $this->verifyAttributes($data, $class);

            foreach ($attrs->click as $t) {
                $events[] = $t;
            }
            foreach ($attrs->change as $t) {
                $events[] = $t;
            }
            foreach ($attrs->input as $t) {
                $events[] = $t;
            }

            if ($data->staticConstruct) {
                $staticConstructors[] = sha1($class);
            }

            if ($this->isClientTagClass($class)) {
                $tags[] = $this->getTagData($class);
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                echo 'Skip metadata for ' . $class . ': ' . $e->getMessage() . "\n";
            }
        }
    }

    private function isClientTagClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === \Flames\Client\Tag::class) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getTagData(string $class): mixed
    {
        $tag = new Arr([
            'class'   => $class,
            'uid'     => null,
            'path'    => null,
            'content' => null,
        ]);

        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            if ($method->name === '__constructStatic') {
                continue;
            }
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === \Flames\Client\Tag::class) {
                    $arguments = $attribute->getArguments();
                    $tag->path = $arguments['path'] ?? $tag->path;
                    $tag->uid  = $arguments['uid']  ?? $tag->uid;
                }
            }
        }

        if ($tag->uid === null) {
            throw new \RuntimeException('Missing tag uid.');
        }
        if ($tag->path === null) {
            throw new \RuntimeException('Missing tag path.');
        }

        $fullPath = APP_PATH . 'Client/View/' . $tag->path;
        if (!file_exists($fullPath)) {
            throw new \RuntimeException("View path {$fullPath} does not exist.");
        }

        $loader       = new \Flames\Mesh\Loader\FilesystemLoader(APP_PATH . 'Client/View/');
        $environment  = new \Flames\Mesh\Environment($loader, []);
        $tag->content = $environment->render($tag->path, []);

        $clientClassName = '__Flames_Tag_' . str_replace('\\', '_', substr($class, 15));
        $uid             = $tag->uid;

        $tag->eval = "
            var template = document.createElement('template');
            template.innerHTML = `
            {$tag->content}
`;
            Flames.Internal.tags['{$uid}'] = { template: template, shadows: [] };

            class {$clientClassName} extends HTMLElement {
                constructor() {
                    super();
                    this.attachShadow({mode: 'open'});
                    this.shadowRoot.appendChild(Flames.Internal.tags['{$uid}'].template.content.cloneNode(true));
                    var shadowId = Flames.Internal.tags['{$uid}'].shadows.length;
                    Flames.Internal.tags['{$uid}'].shadows[shadowId] = this.shadowRoot;
                    Flames.Internal.evalBase64(btoa('\\\\Flames\\\\Surface\\\\Runtime\\\\Dispatch\\\\Tag::run(\\'{$uid}\\',\\'' + shadowId + '\\');'));
                }
                connectedCallback() {
                    var shadowsCount = Flames.Internal.tags['{$uid}'].shadows.length;
                    var shadowId = null;
                    for (var i = 0; i < shadowsCount; i++) {
                        if (this.shadowRoot === Flames.Internal.tags['{$uid}'].shadows[i]) { shadowId = i; break; }
                    }
                    Flames.Internal.evalBase64(btoa('\\\\Flames\\\\Surface\\\\Runtime\\\\Dispatch\\\\Tag::render(\\'{$uid}\\',\\'' + shadowId + '\\');'));
                }
            }
            window.customElements.define('{$uid}', {$clientClassName});
        ";

        return $tag;
    }

    private function verifyAttributes(mixed $data, string $class): Arr
    {
        $attributes = new Arr(['click' => new Arr(), 'change' => new Arr(), 'input' => new Arr()]);

        foreach ($data->methods as $method) {
            $method->class = $class;
            match ($method->type) {
                'click'  => ($attributes->click[]  = $method),
                'change' => ($attributes->change[] = $method),
                'input'  => ($attributes->input[]  = $method),
                default  => null,
            };
        }

        return $attributes;
    }

    /** @param resource $stream */
    private function finish(mixed $stream): void
    {
        fwrite($stream, "\n\n");
        fclose($stream);

        $this->publishPublicApp();

        if ($this->debug) {
            echo "\nAssets build successfully\n";
        }
    }

    private function publishPublicApp(): void
    {
        $source = self::BASE_PATH . 'Flames.js';
        if (!is_file($source)) {
            return;
        }

        $destDir = dirname(self::PUBLIC_APP_PATH);
        if (!is_dir($destDir)) {
            $mask = umask(0);
            mkdir($destDir, 0777, true);
            umask($mask);
        }

        copy($source, self::PUBLIC_APP_PATH);
    }

    /**
     * @param array<string, string> $buffers
     * @param class-string          $class
     */
    private function appendOptionalRuntimeClass(array &$buffers, string $class): void
    {
        $path = $this->resolveClassFile($class);
        if ($path === null) {
            return;
        }

        $buffers[sha1($class)] = $this->loadPhpFile($path);
    }

    /** @param resource $stream */
    private function writeSurfaceBootstrap(mixed $stream): void
    {
        fwrite($stream, 'window.Flames=window.Flames||{};');
        fwrite($stream, 'window.Flames.Surface=window.Flames.Surface||{};');
        fwrite($stream, 'window.dump=window.dump||console.log.bind(console);');
        fwrite($stream, 'window.Flames.Internal=window.Flames.Internal||{};');
        fwrite($stream, 'window.Flames.Internal.unserialize=function(v){return Flames.Serialize.unserialize(v);};');
        fwrite($stream, 'window.Flames.Internal.serialize=function(v){return Flames.Serialize.serialize(v);};');
        fwrite($stream, 'window.Flames.Internal.tags=window.Flames.Internal.tags||[];');
        fwrite($stream, 'window.Flames.Internal.modules=window.Flames.Internal.modules||{};');
        fwrite($stream, 'window.Flames.Internal.evalBase64=async function(b64){');
        fwrite($stream, 'var php=Flames.Surface._php||(Flames.Surface._php=new Flames.Surface.PhpWeb());');
        // php-wasm only reports PHP output/errors through events; exec() evaluates a single expression.
        fwrite($stream, 'if(!Flames.Surface._phpListeners&&typeof php.addEventListener==="function"){Flames.Surface._phpListeners=true;');
        fwrite($stream, 'var txt=function(d){return Array.isArray(d)?d.join(""):String(d);};');
        fwrite($stream, 'php.addEventListener("output",function(e){var s=txt(e.detail);if(s.trim()!==""){console.log(s);}});');
        fwrite($stream, 'php.addEventListener("error",function(e){var s=txt(e.detail);if(s.trim()!==""){console.error("[Flames] PHP:",s);}});}');
        fwrite($stream, 'var code=new TextDecoder().decode(Uint8Array.from(atob(b64),function(c){return c.charCodeAt(0);})).trim();');
        fwrite($stream, 'if(code.indexOf("<?")!==0){code="<?php\\n"+code;}');
        fwrite($stream, 'var exitCode=await php.run(code);if(exitCode!==0){console.error("[Flames] PHP run exit code",exitCode);}return null;};');
        $this->writeSurfaceDomCompat($stream);
    }

    /** @param resource $stream */
    private function writeSurfaceDomCompat(mixed $stream): void
    {
        fwrite($stream, 'window.Flames.Internal.char=window.Flames.Internal.char||"\u30ed";');
        fwrite($stream, 'window.Flames.Internal.uid=window.Flames.Internal.uid||0;');
        fwrite($stream, 'window.Flames.Internal.asyncRedirect=window.Flames.Internal.asyncRedirect||"false";');
        fwrite($stream, 'if(typeof Flames.Internal.Hashid!=="undefined"&&!window.Flames.Internal.hashidfy){');
        fwrite($stream, 'window.Flames.Internal.hashidfy=new Flames.Internal.Hashid("",14,"abcdefghijklmnopqrstuvwxyz0123456789","");}');
        fwrite($stream, 'if(typeof window.Flames.Internal.generateUid!=="function"){');
        fwrite($stream, 'window.Flames.Internal.generateUid=function(){window.Flames.Internal.uid+=1;');
        fwrite($stream, 'if(window.Flames.Internal.hashidfy&&typeof window.Flames.Internal.hashidfy.encode==="function"){');
        fwrite($stream, 'return window.Flames.Internal.hashidfy.encode([window.Flames.Internal.uid]);}');
        fwrite($stream, 'return "u"+window.Flames.Internal.uid.toString(36);};}');
        fwrite($stream, 'if(!Array.prototype.toPhpSerialize){Array.prototype.toPhpSerialize=function(){return Flames.Internal.serialize(this);};}');
        fwrite($stream, 'if(!DOMTokenList.prototype.toArray){DOMTokenList.prototype.toArray=function(){return this.value.split(" ");};}');
        fwrite($stream, 'if(!NodeList.prototype.toPhpSerializeUids){NodeList.prototype.toPhpSerializeUids=function(){');
        fwrite($stream, 'var array=[],char=Flames.Internal.char;');
        fwrite($stream, 'for(var i=0;i<this.length;i++){var element=this[i];var uid=element.getAttribute(char+"uid");');
        fwrite($stream, 'if(uid===null){uid=Flames.Internal.generateUid();element.setAttribute(char+"uid",uid);}');
        fwrite($stream, 'array.push(uid);}return array.toPhpSerialize();};}');
        fwrite($stream, 'window.Flames.Internal.runAutoBuildWatcher=function(){');
        fwrite($stream, 'var el=document.querySelector("flames-autobuild");if(!el){return;}');
        fwrite($stream, 'window.Flames.Internal.autoBuildHash=el.innerHTML;el.remove();');
        fwrite($stream, 'window.Flames.Internal.runAutoBuild=function(){');
        fwrite($stream, 'var xhr=new XMLHttpRequest();xhr.open("POST","/flames/auto/build");');
        fwrite($stream, 'xhr.setRequestHeader("Content-Type","application/json;charset=UTF-8");');
        fwrite($stream, 'xhr.onreadystatechange=function(){if(xhr.status===200&&xhr.readyState===4){');
        fwrite($stream, 'var data=JSON.parse(xhr.responseText);');
        fwrite($stream, 'if(data.changed===false){setTimeout(function(){Flames.Internal.runAutoBuild();},250);}');
        fwrite($stream, 'else{location.reload();}}};');
        fwrite($stream, 'xhr.send(JSON.stringify({hash:Flames.Internal.autoBuildHash}));};');
        fwrite($stream, 'Flames.Internal.runAutoBuild();};');
    }

    private function jsEvalBase64(string $encoded): string
    {
        return 'await Flames.Internal.evalBase64(\'' . $encoded . '\');';
    }

    private function verifyAuto(): void
    {
        if ($this->auto) {
            new Automate()->run($this->debug);
        }
    }
}
