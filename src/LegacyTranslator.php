<?php

namespace NicolasBeauvais\LegacyTranslator;

use DiDom\Element;
use Illuminate\View\View;

class LegacyTranslator
{
    /**
     * @var \DiDom\Element
     */
    protected $current;

    /**
     * @var string Translation key.
     */
    protected $key;

    /**
     * @var Element[]
     */
    protected $elements = [];

    /**
     * @var View
     */
    protected $view;

    /**
     * LegacyTranslator constructor.
     */
    public function __construct(View $view, array $elements = [], $key = null)
    {
        $this->elements = $elements;
        $this->view = $view;
        $this->key = $this->defineKey($view, $key);
    }

    private function defineKey(View $view, $key) : string
    {
        $key = str_replace('.', '/', $key ? $key : $view->getName());

        if (config('laravel-legacy-translator.view_ignore_first_dir')) {
            $pieces = explode('/', $key);
            array_shift($pieces);
            $key = implode('/', $pieces);
        }

        return $key;
    }

    /**
     * @param \DiDom\Element[] $elements
     */
    public function findString()
    {
        array_shift($this->elements);
        $this->removeDuplicateElements();

        foreach ($this->elements as $child) {
            array_shift($this->elements);

            if (!$this->hasTextNode($child)) {
                continue;
            }

            return $child;
        }

        return null;
    }

    private function removeDuplicateElements()
    {
        foreach ($this->elements as $key => $element) {
            if ($key === 0) {
                continue;
            }

            if (!$this->hasTextNode($element)) {
                unset($this->elements[$key]);
                continue;
            }

            if (isset($this->elements[$key - 1]) && $element->parent()->is($this->elements[$key - 1])) {
                unset($this->elements[$key]);
            }
        }

        $this->elements = array_values($this->elements);
    }

    /**
     * @param Element $element
     */
    private function hasTextNode(Element $element)
    {
        $children = $element->children();

        foreach ($children as $child) {
            if ($child->isTextNode() && !empty(trim($child->text()))) {
                return $this->setCurrent($child->parent());
            }
        }

        return false;
    }

    private function setCurrent(Element $element) : Element
    {
        return $this->current = $element;
    }

    public function makeKey(Element $element) : string
    {
        $key = iconv('UTF-8', 'ASCII//TRANSLIT', strtolower(explode(' ', trim($element->text()))[0]));

        if (config('laravel-legacy-translator.lang_prefix')) {
            $key = config('laravel-legacy-translator.lang_prefix') . '.' . $key;
        }

        return $this->key . '.' . $key;
    }

    public function tableElement(Element $element) : array
    {
        return [
            html_entity_decode($this->cleanElement($element)->html()),
            $this->getTranslatedElement($element, $this->hasAttribute($element)),
            $this->getTextForTranslation($element),
        ];
    }

    public function hasAttribute(Element $element) : bool
    {
        return str_contains($this->getTextForTranslation($element), ':attribute');
    }

    private function cleanElement(Element $element, $attributes = false) : Element
    {
        $element = $element->cloneNode();

        if ($attributes) {
            return $element;
        }

        foreach ($element->attributes() as $attribute => $value) {
            $element->removeAttribute($attribute);
        }

        return $element;
    }

    public function emptyElement(Element $element) : Element
    {
        if (count($element->children()) > 1) {
            foreach ($element->children() as $child) {
                $child->remove();
            }
        }

        return $element;
    }

    public function getTranslatedElement(Element $element, bool $hasAttributes = false, $key = null) : Element
    {
        $cleanElement = $this->emptyElement($this->cleanElement($element, $key ? true : false));
        $content = $key ? "'$key'" : "'{$this->makeKey($element)}'";

        if ($hasAttributes) {
            $content .= ", []";
        }

        return $cleanElement->setInnerHtml("{!! trans($content) !!}");
    }

    private function getTextForTranslation(Element $element) : string
    {
        LegacyTranslatorBladeCompiler::setCompileWrappedEchos(false);

        $element = $this->cleanElement($element);

        if (count($element->children()) === 1) {
            return app('blade.compiler')->compileString($element->text());
        }

        $content = '';
        $children = $element->children();

        foreach ($children as $child) {
            $content .= $child->isTextNode() ? $child->text() : $child->html();
        }

        return trim(app('blade.compiler')->compileString($content));
    }

    public function translateElement(Element $element, string $key)
    {
        $this->translateElementInTemplate($element, $key);

        if (trans($key) === $key) {
            $this->createLaravelTranslation($element, $key);
        }
    }

    private function translateElementInTemplate(Element $element, string $key)
    {
        $this->replaceInView(
            html_entity_decode($element->html()),
            $this->getTranslatedElement($element, $this->hasAttribute($element), $key)->html()
        );
    }

    private function replaceInView(string $search, string $replace)
    {
        $content = str_replace($search, $replace, file_get_contents($this->view->getPath()));

        file_put_contents($this->view->getPath(), $content);
    }

    private function createLaravelTranslation($element, $key)
    {
        list($namespace, $group, $item) = app('translator')->parseKey($key);
        $locale = app()->getLocale();

        $translationsDot = array_dot(app('translation.loader')->load($locale, $group, $namespace));
        $translationsDot[$item] = $this->getTextForTranslation($element);

        $translations = [];
        foreach ($translationsDot as $key => $value) {
            array_set($translations, $key, $value);
        }

        $this->writeFile(resource_path("lang/{$locale}/{$group}.php"), $translations);
    }

    /**
     * Write a language file from array.
     *
     * Thx to https://github.com/themsaid/laravel-langman/blob/master/src/Manager.php
     *
     * @param string $filePath
     * @param array $translations
     * @return void
     */
    public function writeFile($filePath, array $translations)
    {
        $content = "<?php \n\nreturn [";
        $content .= $this->stringLineMaker($translations);
        $content .= "\n];";
        file_put_contents($filePath, $content);
    }

    /**
     * Write the lines of the inner array of the language file.
     *
     * Thx to https://github.com/themsaid/laravel-langman/blob/master/src/Manager.php
     *
     * @param $array
     * @return string
     */
    private function stringLineMaker($array, $prepend = '')
    {
        $output = '';
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = $this->stringLineMaker($value, $prepend.'    ');
                $output .= "\n{$prepend}    '{$key}' => [{$value}\n{$prepend}    ],";
            } else {
                $value = str_replace('\"', '"', addslashes($value));
                $output .= "\n{$prepend}    '{$key}' => '{$value}',";
            }
        }
        return $output;
    }
}
