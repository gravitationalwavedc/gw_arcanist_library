<?php

include "impl/JestUnitTestEngineImpl.php";

final class JestUnitTestEngine extends ArcanistUnitTestEngine {
    public function run() {
        $jestUnitTestEngine = new JestUnitTestEngineImpl($this);

        return $jestUnitTestEngine->run();
    }
}
