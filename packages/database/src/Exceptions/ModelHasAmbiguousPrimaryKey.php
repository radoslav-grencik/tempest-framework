<?php

declare(strict_types=1);

namespace Tempest\Database\Exceptions;

use Exception;

final class ModelHasAmbiguousPrimaryKey extends Exception implements DatabaseException {}
