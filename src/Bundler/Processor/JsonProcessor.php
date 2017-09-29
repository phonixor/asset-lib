<?php
namespace Hostnet\Component\Resolver\Bundler\Processor;

use Hostnet\Component\Resolver\Bundler\ContentItem;
use Hostnet\Component\Resolver\Bundler\ContentState;
use Hostnet\Component\Resolver\Bundler\Pipeline\ContentProcessorInterface;
use Hostnet\Component\Resolver\Import\Nodejs\Executable;
use Hostnet\Component\Resolver\Bundler\TranspileException;
use Symfony\Component\Process\ProcessBuilder;

final class JsonProcessor implements ContentProcessorInterface
{
    public function supports(ContentState $state): bool
    {
        return $state->current() === ContentState::UNPROCESSED && $state->extension() === 'json';
    }

    public function peek(string $cwd, ContentState $state): void
    {
        $state->transition(ContentState::PROCESSED, 'json');
    }

    public function transpile(string $cwd, ContentItem $item): void
    {
        $js = 'return ';
        $js .= $item->getContent();
        $js .= ";\n";

        $item->transition(ContentState::PROCESSED, $js, 'js');
    }
}