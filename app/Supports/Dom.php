<?php

namespace App\Supports;

use IvoPetkov\HTML5DOMDocument;

trait Dom
{
    protected function loadDom(string $html): HTML5DOMDocument
    {
        $dom = new HTML5DOMDocument();
        $dom->loadHTML($html);

        return $dom;
    }
}
