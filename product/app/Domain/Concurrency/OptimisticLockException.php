<?php

namespace App\Domain\Concurrency;

use DomainException;

final class OptimisticLockException extends DomainException {}
