<?php

namespace App\Policies;

/**
 * Registered since the guidelines feature shipped and, until the tenancy
 * refactor, never once invoked — BrandGuidelineController hand-rolled the same
 * pivot query inline instead.
 */
class BrandGuidelinePolicy extends CustomerOwnedPolicy {}
