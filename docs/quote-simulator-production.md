# Simulateur de devis — production

## Architecture

La grille tarifaire est la seule source des montants. Elle est stockée en base dans
`portfolio_quote_pricing` (une ligne unique : `catalog` JSON + `version`) et servie par
`GET /api/quote-pricing`. Le frontend Vue ne contient aucun montant : il affiche ce que
le catalogue renvoie.

Trois endpoints publics distincts :

| Endpoint | Rôle | Écrit en base | Appelle DeepSeek | Envoie un email |
| --- | --- | --- | --- | --- |
| `POST /api/quote-recommendations` | propose deux périmètres à partir du besoin décrit | non | oui | non |
| `POST /api/quote-estimates/preview` | affiche le prix avant les coordonnées | non | non | non |
| `POST /api/quote-estimates` | demande finale avec coordonnées | oui | oui | oui |

## Ce que l'IA a le droit de faire

DeepSeek ne renvoie que des **clés du catalogue** et un texte court : `summary` et une
justification par clé. Toute clé absente de l'offre active est supprimée côté serveur,
et une justification ne survit que si elle est rattachée à une clé conservée.

Les titres, les inclusions, les options et les montants affichés viennent **tous** du
catalogue. Conséquence directe : le modèle ne peut ni inventer un livrable, ni annoncer
un prix. Les deux propositions sont chiffrées par `QuoteEstimateCalculator`, le même
calculateur que le reste du parcours, et fusionnées si elles sont identiques.

Garde-fous de l'appel : description d'au moins **30 caractères** (sinon aucun appel
payant), quota dédié de **10 par heure et par IP**, délai maximal de **5 secondes**.
Panne, quota atteint ou réponse inexploitable laissent le choix manuel disponible :
l'écran de sélection est affiché avant l'appel et ne dépend jamais de sa réponse.

## Modes de prix

Chaque variante porte un `pricingMode` et un supplément d'urgence fixe (`priorityAmount`) :

| Mode | Affichage | Mention |
| --- | --- | --- |
| `fixed` | un montant | « Prix ferme pour le périmètre décrit ci-dessus. » |
| `from` | « à partir de X » | « Prix de départ pour le périmètre décrit… » |
| `range` | deux bornes | « Estimation indicative, non contractuelle… » |

Une offre qui contient au moins une variante `fixed` ou `from` n'accepte que des options
à montant unique, et le supplément de rédaction doit l'être aussi : sinon un pack ferme
redeviendrait une fourchette dès la première option cochée. La validation le refuse.

Le délai prioritaire est un **montant fixe par variante** ; aucun multiplicateur global
n'est appliqué.

Les deux recalculent le montant côté serveur à partir du catalogue actif : un montant
envoyé par le client est toujours ignoré.

Chaque offre porte un drapeau `contentQuestion` : la question « Avez-vous déjà vos
textes et vos images ? » n'est posée — et facturée — que pour les offres qui livrent
du contenu éditorial (site vitrine, refonte). Le frontend lit ce drapeau dans le
catalogue ; le calculateur applique la même règle côté serveur.

L'éditeur admin (`/admin/quote-pricing` → `PUT /api/admin/quote-pricing`) modifie la
grille sans redéploiement et incrémente `version`. Chaque estimation enregistrée
conserve ses montants et sa `pricing_version` : une modification de grille ne réécrit
jamais l'historique.

## Variables serveur

Configurer ces variables uniquement dans l'environnement privé du backend Symfony :

```dotenv
DEEPSEEK_API_KEY=replace_with_a_rotated_private_key
DEEPSEEK_MODEL=deepseek-chat
DEEPSEEK_QUOTE_MAX_TOKENS=800
DEEPSEEK_QUOTE_TEMPERATURE=0.3
DEEPSEEK_RECOMMENDATION_TIMEOUT=5
```

La clé DeepSeek ne doit jamais être copiée dans le frontend Vue, un fichier `dist`, un
commit Git ou une documentation. Toute clé partagée dans une conversation doit être
révoquée et remplacée avant le déploiement.

`DEEPSEEK_QUOTE_MAX_TOKENS` doit rester à 800 au minimum : en dessous, la réponse JSON
du modèle est tronquée et le service bascule systématiquement sur sa synthèse de secours.

Vérifier également les variables existantes `CONTACT_RECIPIENT_EMAIL`,
`MAILER_FROM_EMAIL`, `BREVO_API_KEY` et `CORS_ALLOW_ORIGIN`. Cette dernière doit
autoriser exactement le domaine HTTPS du frontend. Si le backoffice utilise une session
entre deux domaines distincts, vérifier en production les attributs `Secure` et
`SameSite` du cookie de session.

Une variable manquante casse le simulateur ; une **dépendance** manquante casse tout le
conteneur Symfony. Après un `composer install`, vérifier que `symfony/rate-limiter` est
bien présent, sinon chaque page renvoie 500.

## Déploiement

1. Sauvegarder la base portfolio et le dossier courant du backend.
2. Ne pas écraser sur le serveur : `.env.prod`, `.ovhconfig`, `.htaccess`, `public/uploads/`.
3. Installer les dépendances avec le lockfile :
   `composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-redis`.
   Ne jamais lancer `composer update` en production : le lockfile est la référence.
4. Vérifier les migrations en attente : `php bin/console doctrine:migrations:status --env=prod`.
5. Appliquer les migrations du simulateur uniquement, si d'autres migrations non liées
   sont en attente :
   `php bin/console doctrine:migrations:execute --up 'DoctrineMigrations\VersionXXXXXXXXXXXXXX' --no-interaction --env=prod`.
6. Vider le cache : `php bin/console cache:clear --env=prod`.
7. Construire et déployer le frontend avec l'URL publique du backend.

Migrations concernées :

| Migration | Effet |
| --- | --- |
| `Version20260915140000` | crée `portfolio_quote_estimates` |
| `Version20260915143000` | met à jour le JSON de la table `content` |
| `Version20260915220000` | crée `portfolio_quote_pricing`, insère la grille initiale, ajoute `pricing_version`, `offer_key`, `variant_key` sur `portfolio_quote_estimates` |
| `Version20260915233000` | ajoute le drapeau `contentQuestion` aux offres de la grille déjà stockée (aucune modification de schéma) |
| `Version20260916090000` | ajoute `pricingMode`, `priorityAmount` et la liste des outils à la grille stockée ; retire le multiplicateur d'urgence (aucune modification de schéma) |
| `Version20260916100000` | incrémente la version de la grille après la transformation afin de distinguer les estimations historiques |

Aucune de ces migrations ne lit ni ne modifie une table `massage_*`.

## Vérifications

### API

1. `GET /api/quote-pricing` renvoie le catalogue, ses outils et une `version`.
2. `POST /api/quote-recommendations` avec une description de moins de 30 caractères
   renvoie `200`, aucune proposition, `source: "description_too_short"`, et **aucun**
   appel DeepSeek n'est facturé.
3. Une clé de variante ou d'option inventée par le modèle n'apparaît dans aucune réponse.
4. Le onzième appel de recommandation dans l'heure renvoie `429` avant tout appel payant.
5. `POST /api/quote-estimates/preview` renvoie un prix **sans** créer de ligne en base.
3. Un montant falsifié dans le corps de la requête finale est ignoré : la réponse contient
   le montant recalculé côté serveur.
4. `POST /api/quote-estimates` renvoie `201`, avec `aiSource` valant `deepseek` ou `fallback`.
5. Une clé DeepSeek absente ou une réponse invalide laisse le montant disponible.
6. Une sixième demande finale dans l'heure renvoie `429` sans appel DeepSeek.
7. `PUT /api/admin/quote-pricing` sans session admin renvoie un refus sans exposer la configuration.

### Parcours public

0. L'écran « Votre solution » est utilisable **immédiatement**, avant la réponse de l'IA :
   formules et options sélectionnables, boutons actifs. Couper le réseau à cet instant ne
   doit rien bloquer.
1. Le prix, les inclusions et les options s'affichent **avant** tout champ de coordonnées.
2. Le prospect qui ne laisse pas ses coordonnées garde son estimation à l'écran.
3. Aucun écran ne demande une complexité ou une notion technique (BDD, API).
4. La question sur les textes et images n'apparaît que pour le site vitrine et la
   refonte ; elle est absente pour l'automatisation, l'assistant IA et l'outil métier.
5. Le parcours reste utilisable au clavier et sur un écran de 390 px.

### Backoffice

1. `/admin/quote-pricing` charge la grille active et affiche sa version.
2. Modifier un minimum, enregistrer, puis relancer un aperçu public : la nouvelle valeur
   s'applique immédiatement, sans rebuild ni redéploiement.
3. Saisir un maximum inférieur au minimum : l'erreur est localisée, le brouillon est
   conservé et rien n'est enregistré.
4. `/admin/quote-estimates` affiche l'offre retenue, le détail du calcul, la synthèse et
   la version de grille utilisée.
5. Une estimation reçue avant une modification de grille conserve son montant, son
   périmètre et sa mention d'origine : tout est figé dans `answers` au moment de l'envoi.
6. L'écran admin distingue la **solution retenue par le client** (facturable) de la
   **qualification IA** (jamais un engagement).
