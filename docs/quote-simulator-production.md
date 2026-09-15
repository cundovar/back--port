# Simulateur de devis — production

## Architecture

La grille tarifaire est la seule source des montants. Elle est stockée en base dans
`portfolio_quote_pricing` (une ligne unique : `catalog` JSON + `version`) et servie par
`GET /api/quote-pricing`. Le frontend Vue ne contient aucun montant : il affiche ce que
le catalogue renvoie.

Deux endpoints publics distincts :

| Endpoint | Rôle | Écrit en base | Appelle DeepSeek | Envoie un email |
| --- | --- | --- | --- | --- |
| `POST /api/quote-estimates/preview` | affiche la fourchette avant les coordonnées | non | non | non |
| `POST /api/quote-estimates` | demande finale avec coordonnées | oui | oui | oui |

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

Aucune de ces migrations ne lit ni ne modifie une table `massage_*`.

## Vérifications

### API

1. `GET /api/quote-pricing` renvoie le catalogue et une `version`.
2. `POST /api/quote-estimates/preview` renvoie une fourchette **sans** créer de ligne en base.
3. Un montant falsifié dans le corps de la requête finale est ignoré : la réponse contient
   le montant recalculé côté serveur.
4. `POST /api/quote-estimates` renvoie `201`, avec `aiSource` valant `deepseek` ou `fallback`.
5. Une clé DeepSeek absente ou une réponse invalide laisse le montant disponible.
6. Une sixième demande finale dans l'heure renvoie `429` sans appel DeepSeek.
7. `PUT /api/admin/quote-pricing` sans session admin renvoie un refus sans exposer la configuration.

### Parcours public

1. La fourchette, les inclusions et les options s'affichent **avant** tout champ de coordonnées.
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
5. Une estimation reçue avant une modification de grille conserve son montant d'origine.
