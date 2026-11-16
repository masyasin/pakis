<?php

namespace App\Support\Import;

/** Lintasan kerangka impor yang meminta `ImportType::context()`. */
enum ImportPhase: string
{
    case Validate = 'validate';
    case Process = 'process';
}
