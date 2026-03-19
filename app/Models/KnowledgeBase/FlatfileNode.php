<?php

namespace App\Models\KnowledgeBase;

class FlatfileNode extends \Guava\FilamentKnowledgeBase\Models\FlatfileNode
{
    protected function sushiShouldCache(): bool
    {
        return false;
    }
}
