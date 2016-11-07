<?php

namespace  NicolasBeauvais\LegacyTranslator\Commands;

use DiDom\Document;
use DiDom\Element;
use DiDom\Query;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\View\View;
use NicolasBeauvais\LegacyTranslator\LegacyTranslator;
use NicolasBeauvais\LegacyTranslator\LegacyTranslatorBladeCompiler;

class SearchCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'llt:search 
    {view? : Blade view to open}
    {--key : The base key for the translation}
    {--namespace=\App\Http\Controllers\ : The root controllers namespace}    
    {-- force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Search the project for untranslated strings.';

    /**
     * @var Document
     */
    protected $document;

    /**
     * @var LegacyTranslator
     */
    private $translator;

    /**
     * Handle command.
     */
    public function handle()
    {
        /** @var View $view */
        $view = view($this->argument('view'));

        $this->makeLegacyTranslator($view, $this->makeDocument($view));

        while ($element = $this->translator->findString()) {
            $this->suggestElement($element);
        }
    }

    public function makeDocument(View $view) : Document
    {
        $compiled = app('blade.compiler')->compileString(file_get_contents($view->getPath()));
        $sanitizedOriginal = preg_replace('/<\?php(.+?)\?>/is', '<blade></blade>', $compiled);

        return new Document($sanitizedOriginal);
    }

    private function makeLegacyTranslator(View $view, Document $document)
    {
        $this->translator = new LegacyTranslator(
            $view,
            $document->find('body')[0]->find('//*[text()]', Query::TYPE_XPATH),
            $this->option('key')
        );
    }

    private function suggestElement(Element $element)
    {
        $this->table(
            ['HTML', 'Translated', 'value'],
            [$this->translator->tableElement($element)]
        );

        $key = $this->translator->makeKey($element);

        $choice = strtolower($this->anticipate("Translate this element ? ([Y]es | [N]o)", [$key, 'Y', 'N'], $key));

        if (in_array($choice, ['n', 'no'])) {
            return;
        }

        $key = !in_array($choice, ['y', 'yes']) ? $choice : $key;

        $key = $this->checkKey($key);

        if ($key === false) {
            return;
        }

        $this->translator->translateElement($element, $key);
    }

    private function checkKey($key)
    {
        while (trans($key) !== $key) {
            $choice = strtolower($this->anticipate(
                "This key already exist ([U]se|[R]ename|[S]kip])",
                [$key, 'Y', 'N'],
                'trans: ' . trans($key)
            ));

            if (in_array($choice, ['s', 'skip'])) {
                return false;
            }

            if (in_array($choice, ['u', 'use'])) {
                break;
            }

            if (in_array($choice, ['r', 'rename'])) {
                $key = strtolower($this->anticipate("New key name", [$key], $key));
            }
        }

        return $key;
    }
}
