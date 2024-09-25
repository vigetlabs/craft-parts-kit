<?php

namespace viget\partskit\models;

class MockAssetBuilder
{
    private MockAsset $asset;

    public function __construct()
    {
        $this->asset = new MockAsset();
    }

    public function width(int $value): self
    {
        $this->asset->setWidth($value);
        return $this;
    }

    public function height(int $value): self
    {
        $this->asset->setHeight($value);
        return $this;
    }

    public function one(): MockAsset
    {
        return $this->asset;
    }
}
