<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * As views do painel usam `@vite` para o bundle AdminLTE, e `/public/build`
     * é gitignored: sem isto, a suíte passaria a exigir `npm run build` para
     * renderizar qualquer página. Os testes leem HTML, não URL de asset.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
