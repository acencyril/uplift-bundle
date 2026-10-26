<?php

// Uplift adoptions: adoption.<id>.title and adoption.<id>.summary (domain "uplift").
return [
    'adoption.map-request.title' => 'Map the request to typed arguments',
    'adoption.map-request.summary' => 'Since Symfony 6.3, #[MapRequestPayload], #[MapQueryString] and #[MapQueryParameter] turn the request body and the query string into typed, validated controller arguments, instead of json_decode($request->getContent()) or $request->query->get() by hand.',
    'adoption.as-command.title' => 'Name commands with #[AsCommand]',
    'adoption.as-command.summary' => 'Since Symfony 5.3, the #[AsCommand] attribute gives a command its name and description and keeps it lazy. The static $defaultName and $defaultDescription properties it replaces are deprecated since Symfony 6.1.',
    'adoption.readonly-promotion.title' => 'Readonly promoted properties',
    'adoption.readonly-promotion.summary' => 'Since PHP 8.1, a property can be readonly: written once, in the constructor, and enforced by PHP. Dependencies promoted in a constructor and never written again can say so.',
    'adoption.lock-attribute.title' => 'A lock attribute on controller actions (experimental)',
    'adoption.lock-attribute.summary' => 'Example adoption, not confirmed: an attribute that would take a lock around a controller action, instead of a LockFactory with createLock(), acquire() and release() by hand. Its package, version and API are to be confirmed; it is never applied.',
];
