<?php

namespace NicolasBeauvais\LegacyTranslator;

use Illuminate\View\Compilers\BladeCompiler;

class LegacyTranslatorBladeCompiler extends BladeCompiler
{
    private static $compileWrappedEchos = true;

    public static function setCompileWrappedEchos(bool $compileWrappedEchos)
    {
        self::$compileWrappedEchos = $compileWrappedEchos;
    }

    /**
     * Compile Blade echos into valid PHP.
     *
     * @param  string  $value
     * @return string
     */
    protected function compileEchos($value)
    {
        foreach ($this->getEchoMethods() as $method => $length) {
            $value = $this->$method($value);
        }

        return $value;
    }

    /**
     * Compile the "raw" echo statements.
     *
     * @param  string  $value
     * @return string
     */
    protected function compileRawEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->rawTags[0], $this->rawTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1] ? substr($matches[0], 1) : '<?php echo '.$this->compileEchoDefaults($matches[2]).'; ?>'.$whitespace;
        };

        if (self::$compileWrappedEchos) {
            $this->getWrappingTag($pattern, $callback);
        } else {
            $this->compileToAttribute($callback);
        }

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * Compile the "regular" echo statements.
     *
     * @param  string  $value
     * @return string
     */
    protected function compileRegularEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->contentTags[0], $this->contentTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            $wrapped = sprintf($this->echoFormat, $this->compileEchoDefaults($matches[2]));

            return $matches[1] ? substr($matches[0], 1) : '<?php echo '.$wrapped.'; ?>'.$whitespace;
        };

        if (self::$compileWrappedEchos) {
            $this->getWrappingTag($pattern, $callback);
        } else {
            $this->compileToAttribute($callback);
        }

        return preg_replace_callback($pattern, $callback, $value);
    }

    /**
     * Compile the escaped echo statements.
     *
     * @param  string  $value
     * @return string
     */
    protected function compileEscapedEchos($value)
    {
        $pattern = sprintf('/(@)?%s\s*(.+?)\s*%s(\r?\n)?/s', $this->escapedTags[0], $this->escapedTags[1]);

        $callback = function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1] ? $matches[0] : '<?php echo e('.$this->compileEchoDefaults($matches[2]).'); ?>'.$whitespace;
        };

        if (self::$compileWrappedEchos) {
            $this->getWrappingTag($pattern, $callback);
        } else {
            $this->compileToAttribute($callback);
        }

        return preg_replace_callback($pattern, $callback, $value);
    }

    private function getWrappingTag(string &$pattern, \Closure &$callback)
    {
        $pattern = '/>[\s\n]*' . substr($pattern, 1, strlen($pattern));
        $pattern = substr($pattern, 0, strlen($pattern) - 2) . '[\s\n]*</s';

        $callback = function ($matches) use ($callback) {
            return '>' . $callback($matches) . '<';
        };
    }

    private function compileToAttribute(\Closure &$callback)
    {
        $callback = function ($matches) {
            return ':attribute';
        };
    }
}
