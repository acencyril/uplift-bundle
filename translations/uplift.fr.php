<?php

// Adoptions Uplift : adoption.<id>.title et adoption.<id>.summary (domaine « uplift »).
return [
    'adoption.map-request.title' => 'Associer la requête à des arguments typés',
    'adoption.map-request.summary' => 'Depuis Symfony 6.3, #[MapRequestPayload], #[MapQueryString] et #[MapQueryParameter] transforment le corps de la requête et la query string en arguments de contrôleur typés et validés, au lieu d\'un json_decode($request->getContent()) ou d\'un $request->query->get() écrits à la main.',
    'adoption.as-command.title' => 'Nommer les commandes avec #[AsCommand]',
    'adoption.as-command.summary' => 'Depuis Symfony 5.3, l\'attribut #[AsCommand] donne à une commande son nom et sa description et la garde paresseuse. Les propriétés statiques $defaultName et $defaultDescription qu\'il remplace sont dépréciées depuis Symfony 6.1.',
    'adoption.readonly-promotion.title' => 'Propriétés promues en readonly',
    'adoption.readonly-promotion.summary' => 'Depuis PHP 8.1, une propriété peut être readonly : écrite une seule fois, dans le constructeur, et PHP le garantit. Les dépendances promues dans un constructeur et jamais réécrites peuvent le dire.',
    'adoption.lock-attribute.title' => 'Un attribut de verrou sur les actions (expérimental)',
    'adoption.lock-attribute.summary' => 'Adoption d\'exemple, non confirmée : un attribut qui prendrait un verrou autour d\'une action de contrôleur, au lieu d\'une LockFactory avec createLock(), acquire() et release() à la main. Son paquet, sa version et son API restent à confirmer ; elle n\'est jamais appliquée.',
];
