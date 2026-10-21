<?php

namespace App\Application;

use DomainException;

// World lock下で同じrequest_idの未成立を確認した後だけ返す確定拒否。
final class BoostReservationRejectedException extends DomainException {}
