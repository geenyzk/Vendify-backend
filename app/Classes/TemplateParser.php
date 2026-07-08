<?php

namespace App\Classes;

class TemplateParser
{
    protected array $context = [];

    public static function make(): self
    {
        return new self();
    }

    public function with(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function parse(string $template): string
    {
        return preg_replace_callback('/{{\s*(.*?)\s*}}/', function ($matches) {
            $keyPath = $matches[1];
            $value = $this->getValueFromContext($keyPath);

            return $value !== null ? $value : $matches[0];
        }, $template);
    }

    protected function getValueFromContext(string $keyPath)
    {
        $segments = explode('.', $keyPath);
        $value = $this->context;

        foreach ($segments as $segment) {
            if (is_array($value) && isset($value[$segment])) {
                $value = $value[$segment];
            } elseif (is_object($value) && isset($value->$segment)) {
                $value = $value->$segment;
            } else {
                return null;
            }
        }

        return $value;
    }
}
