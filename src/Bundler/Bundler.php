<?php
namespace Hostnet\Component\Resolver\Bundler;

use Hostnet\Component\Resolver\Config;
use Hostnet\Component\Resolver\File;
use Hostnet\Component\Resolver\Import\Dependency;
use Hostnet\Component\Resolver\Import\ImportFinderInterface;
use Hostnet\Component\Resolver\Transform\TransformerInterface;
use Hostnet\Component\Resolver\Transpile\TranspileException;
use Hostnet\Component\Resolver\Transpile\TranspileResult;
use Hostnet\Component\Resolver\Transpile\TranspilerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bundler for entry points. This can transpile resources and writes them to files.
 */
class Bundler
{
    private $finder;
    private $transpiler;
    private $transformer;
    private $module_wrapper;
    private $logger;
    private $config;

    private $cwd;

    public function __construct(
        ImportFinderInterface $finder,
        TranspilerInterface $transpiler,
        TransformerInterface $transformer,
        JsModuleWrapperInterface $module_wrapper,
        LoggerInterface $logger,
        Config $config
    ) {
        $this->finder = $finder;
        $this->transpiler = $transpiler;
        $this->transformer = $transformer;
        $this->module_wrapper = $module_wrapper;
        $this->logger = $logger;
        $this->config = $config;

        $this->cwd = $config->cwd();
    }

    /**
     * Bundle a list of entry points, each entry point will be written to their
     * own file.
     */
    public function bundle()
    {
        $output_folder = $this->config->getWebRoot() . '/' . $this->config->getOutputFolder();
        $source_dir = (!empty($this->config->getSourceRoot()) ? $this->config->getSourceRoot() . '/' : '');

        foreach ($this->config->getEntryPoints() as $file_name) {
            $file        = new File($source_dir . $file_name);
            $entry_point = new EntryPoint($file, $this->finder->all($file));

            $this->logger->debug('Checking entry-point {name}', ['name' => $entry_point->getFile()->getPath()]);

            if ($this->checkIfAnyChanged($entry_point->getBundleFile($output_folder), $entry_point->getBundleFiles())) {
                $this->logger->debug(' * Compiling bundle for {name}', ['name' => $entry_point->getFile()->getPath()]);

                $this->compileFile($entry_point->getBundleFile($output_folder), $entry_point->getBundleFiles());
            } else {
                $this->logger->debug(' * Nothing to do for bundle');
            }

            if ($this->checkIfAnyChanged($entry_point->getVendorFile($output_folder), $entry_point->getVendorFiles())) {
                $this->logger->debug(' * Compiling vendors for {name}', ['name' => $entry_point->getFile()->getPath()]);

                $this->compileFile($entry_point->getVendorFile($output_folder), $entry_point->getVendorFiles());
            } else {
                $this->logger->debug(' * Nothing to do for vendor');
            }

            $this->compileAsset($entry_point->getAssetFiles());
        }
    }

    /**
     * Bundle a list of assets, each asset will be written to their own file.
     */
    public function compile()
    {
        $source_dir = (!empty($this->config->getSourceRoot()) ? $this->config->getSourceRoot() . '/' : '');

        foreach ($this->config->getAssetFiles() as $file_name) {
            $file        = new File($source_dir . $file_name);
            $entry_point = new EntryPoint($file, $this->finder->all($file));

            $this->logger->debug('Checking asset {name}', ['name' => $entry_point->getFile()->getPath()]);

            $this->compileAsset(array_map(function (Dependency $d) {
                return $d->getFile();
            }, array_filter($entry_point->getBundleFiles(), function (Dependency $d) {
                return !$d->isVirtual();
            })));
        }
    }

    /**
     * @param File[] $asset_files
     */
    private function compileAsset(array $asset_files)
    {
        $output_folder = $this->config->getWebRoot() . '/' . $this->config->getOutputFolder();

        foreach ($asset_files as $asset_file) {
            $base_dir = trim(substr($asset_file->getDirectory(), strlen($this->config->getSourceRoot())), '/');

            if (strlen($base_dir) > 0) {
                $base_dir .= '/';
            }

            $output_file_name = $asset_file->getBaseName() . '.' . $this->transpiler->getExtensionFor($asset_file);
            $output_file      = new File($output_folder . '/' . $base_dir . $output_file_name);

            if (!file_exists($this->cwd . '/' . $output_file->getDirectory())) {
                mkdir($this->cwd . '/' . $output_file->getDirectory(), 0777, true);
            }

            if ($this->checkIfAnyChanged($output_file, [new Dependency($asset_file)])) {
                // Transpile
                $result = $this->getCompiledContentForCached($asset_file);

                // Transform PRE_WRITE
                $content = $this->transformer->onPreWrite(
                    $output_file,
                    $result->getContent(),
                    $this->config->getOutputFolder()
                );

                file_put_contents($this->cwd . '/' . $output_file->getPath(), $content);
            }
        }
    }

    /**
     * Compile a file.
     *
     * @param File         $output_file
     * @param Dependency[] $dependencies
     */
    private function compileFile(File $output_file, array $dependencies)
    {
        // make sure folder exists
        if (!file_exists($this->cwd . '/' . $output_file->getDirectory())) {
            mkdir($this->cwd . '/' . $output_file->getDirectory(), 0777, true);
        }

        $output_content = '';

        try {
            foreach ($dependencies as $dependency) {
                if ($dependency->isVirtual()) {
                    continue;
                }

                $file = $dependency->getFile();

                $result = $this->getCompiledContentForCached($file);

                // Wrap
                $content = $this->module_wrapper->wrapModule(
                    $file->getPath(), // Use the old file, since we need to resolve dependencies
                    $result->getModuleName(),
                    $result->getContent()
                );

                $output_content .= $content;
            }
        } catch (TranspileException $e) {
            $this->logger->error($e->getErrorOutput());

            throw $e;
        }

        // Transform PRE_WRITE
        $output_content = $this->transformer->onPreWrite(
            $output_file,
            $output_content,
            $this->config->getOutputFolder()
        );

        file_put_contents($this->cwd . '/' . $output_file->getPath(), $output_content);
    }

    /**
     * Return the TranspileResult for a given file. This can also return a
     * cached output to speed things up.
     *
     * @param File $file
     * @return TranspileResult
     */
    private function getCompiledContentForCached(File $file): TranspileResult
    {
        $new_ext     = $this->transpiler->getExtensionFor($file);
        $output_file = new File($file->getDirectory() . '/' . $file->getBaseName() . '.' . $new_ext);

        if (!$this->config->isDev()) {
            return $this->getCompiledContentFor($file, $output_file);
        }

        $cache_key = $this->createFileCacheKey($output_file);

        if ($this->checkIfChanged(
            $this->config->getCacheDir() . '/' . $cache_key,
            $this->cwd . '/' . $file->getPath()
        )) {
            $result = $this->getCompiledContentFor($file, $output_file);

            file_put_contents(
                $this->config->getCacheDir() . '/' . $cache_key,
                serialize([$result->getModuleName(), $result->getContent()])
            );

            $module_name = $result->getModuleName();
            $content = $result->getContent();
        } else {
            $this->logger->debug('  - Emitting {name} (from cache)', ['name' => $file->getPath()]);

            [$module_name, $content] = unserialize(file_get_contents(
                $this->config->getCacheDir() . '/' . $cache_key
            ), []);
        }

        return new TranspileResult($module_name, $content);
    }

    /**
     * Return the TranspileResult for a given file.
     *
     * @param File $file
     * @param File $output_file
     * @return TranspileResult
     */
    private function getCompiledContentFor(File $file, File $output_file): TranspileResult
    {
        $this->logger->debug('  - Emitting {name}', ['name' => $file->getPath()]);

        // Transpile
        $result = $this->transpiler->transpile($file);
        $module_name = $result->getModuleName();

        // Transform
        $content = $this->transformer->onPostTranspile(
            $output_file,
            $result->getContent(),
            $this->config->getOutputFolder()
        );

        return new TranspileResult($module_name, $content);
    }

    /**
     * Check if the output file is newer than the input files.
     *
     * @param File         $output_file
     * @param Dependency[] $input_files
     * @return bool
     */
    private function checkIfAnyChanged(File $output_file, array $input_files): bool
    {
        if ($this->config->isDev()) {
            // did the sources change?
            $sources_file = $this->config->getCacheDir() . '/' . $this->createFileCacheKey($output_file) . '.sources';
            $input_sources = array_map(function (Dependency $d) {
                return $d->getFile()->getPath();
            }, $input_files);

            sort($input_sources);

            if (!file_exists($sources_file)) {
                file_put_contents($sources_file, serialize($input_sources));

                return true;
            }

            $sources = unserialize(file_get_contents($sources_file), []);

            if (count(array_diff($sources, $input_sources)) > 0 || count(array_diff($input_sources, $sources)) > 0) {
                file_put_contents($sources_file, serialize($input_sources));

                return true;
            }
        }

        // Did the files change?
        $file_path = $this->cwd . '/' . $output_file->getPath();
        $mtime = file_exists($file_path) ? filemtime($file_path) : -1;

        if ($mtime === -1) {
            return true;
        }

        foreach ($input_files as $input_file) {
            if ($mtime < filemtime($this->cwd . '/' . $input_file->getFile()->getPath())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the output file is newer than the input file.
     *
     * @param string $output_file
     * @param string $input_file
     * @return bool
     */
    private function checkIfChanged(string $output_file, string $input_file): bool
    {
        $mtime = file_exists($output_file) ? filemtime($output_file) : -1;

        if ($mtime === -1) {
            return true;
        }

        return $mtime < filemtime($input_file);
    }

    /**
     * Create a cache key for a file. This must be unique for a file, but
     * always the same for each file and it's location. The same file in a
     * different folder should have a different key.
     *
     * @param File $output_file
     * @return string
     */
    private function createFileCacheKey(File $output_file): string
    {
        return substr(md5($output_file->getPath()), 0, 5) . '_' . str_replace('/', '.', $output_file->getPath());
    }
}
