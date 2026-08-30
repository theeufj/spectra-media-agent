<?php

namespace App\Policies;

/**
 * Ownership for customer-owned models with no rules of their own.
 *
 * Registering this is what stops a model being reachable with no policy at all.
 * A model that later needs a real rule gets its own class extending
 * {@see CustomerOwnedPolicy}; until then, "you must belong to the customer that
 * owns it" is the whole answer, and it should be declared rather than assumed.
 */
class GenericCustomerPolicy extends CustomerOwnedPolicy {}
