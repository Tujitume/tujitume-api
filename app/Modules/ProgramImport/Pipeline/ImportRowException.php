<?php

namespace App\Modules\ProgramImport\Pipeline;

/** A row cannot be committed for a reason found only at write time (reported on the row, never a 500). */
class ImportRowException extends \RuntimeException {}
