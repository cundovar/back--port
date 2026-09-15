<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create portfolio_quote_pricing with the initial editable quote catalog.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE portfolio_quote_pricing (
            id INT AUTO_INCREMENT NOT NULL,
            catalog JSON NOT NULL,
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql(
            'INSERT INTO portfolio_quote_pricing (catalog, version, updated_at) VALUES (?, 1, NOW())',
            [self::INITIAL_CATALOG]
        );

        $this->addSql('ALTER TABLE portfolio_quote_estimates
            ADD pricing_version INT NOT NULL DEFAULT 1,
            ADD offer_key VARCHAR(60) DEFAULT NULL,
            ADD variant_key VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_quote_estimates
            DROP pricing_version,
            DROP offer_key,
            DROP variant_key');
        $this->addSql('DROP TABLE portfolio_quote_pricing');
    }

    private const INITIAL_CATALOG = <<<'JSON'
{
    "offers": [
        {
            "key": "site-vitrine",
            "label": "Présenter mon activité en ligne",
            "summary": "Un site qui explique ce que vous faites et vous amène des demandes.",
            "variants": [
                {
                    "key": "landing-page",
                    "label": "Une page unique, claire et rapide",
                    "minimumAmount": 350,
                    "maximumAmount": 650,
                    "includes": [
                        "Une page complète avec vos services et vos preuves",
                        "Un formulaire de contact relié à votre email",
                        "Affichage adapté au mobile"
                    ]
                },
                {
                    "key": "wordpress-vitrine",
                    "label": "Un site de plusieurs pages que vous pouvez modifier",
                    "minimumAmount": 600,
                    "maximumAmount": 1100,
                    "includes": [
                        "Jusqu’à 5 pages (accueil, services, à propos, contact…)",
                        "Interface WordPress pour modifier vos textes vous-même",
                        "Formulaire de contact et mentions légales"
                    ]
                },
                {
                    "key": "wordpress-avance",
                    "label": "Un site plus complet, avec des fonctions métier",
                    "minimumAmount": 1100,
                    "maximumAmount": 2200,
                    "includes": [
                        "Site multi-pages avec une structure sur mesure",
                        "Mise en avant de vos offres et de vos contenus",
                        "Accompagnement à la prise en main"
                    ]
                }
            ],
            "options": [
                {
                    "key": "prise-rdv",
                    "label": "Prise de rendez-vous en ligne",
                    "minimumAmount": 150,
                    "maximumAmount": 350
                },
                {
                    "key": "blog",
                    "label": "Espace actualités ou blog",
                    "minimumAmount": 120,
                    "maximumAmount": 300
                },
                {
                    "key": "multilingue",
                    "label": "Site en deux langues",
                    "minimumAmount": 250,
                    "maximumAmount": 600
                },
                {
                    "key": "seo-local",
                    "label": "Être trouvable sur votre ville",
                    "minimumAmount": 150,
                    "maximumAmount": 400
                },
                {
                    "key": "paiement-en-ligne",
                    "label": "Encaisser un paiement en ligne",
                    "minimumAmount": 300,
                    "maximumAmount": 700
                }
            ]
        },
        {
            "key": "automatisation",
            "label": "Arrêter de refaire la même tâche",
            "summary": "Les actions répétitives sont faites automatiquement, sans vous.",
            "variants": [
                {
                    "key": "automatisation-ciblee",
                    "label": "Une tâche précise à automatiser",
                    "minimumAmount": 300,
                    "maximumAmount": 600,
                    "includes": [
                        "Une automatisation prête à l’emploi",
                        "Un historique de ce qui a été fait",
                        "Une courte formation pour rester autonome"
                    ]
                },
                {
                    "key": "automatisation-multi-outils",
                    "label": "Plusieurs outils à faire communiquer",
                    "minimumAmount": 700,
                    "maximumAmount": 1500,
                    "includes": [
                        "Les informations circulent entre vos outils",
                        "Un tableau de suivi simple",
                        "Des alertes en cas d’anomalie"
                    ]
                }
            ],
            "options": [
                {
                    "key": "relances-auto",
                    "label": "Relances automatiques par email",
                    "minimumAmount": 120,
                    "maximumAmount": 300
                },
                {
                    "key": "rapport-hebdo",
                    "label": "Rapport récapitulatif chaque semaine",
                    "minimumAmount": 100,
                    "maximumAmount": 250
                },
                {
                    "key": "import-export",
                    "label": "Reprise de vos fichiers existants",
                    "minimumAmount": 150,
                    "maximumAmount": 400
                }
            ]
        },
        {
            "key": "assistant-ia",
            "label": "Un assistant IA pour mon activité",
            "summary": "Un assistant qui répond, trie ou rédige à partir de vos informations.",
            "variants": [
                {
                    "key": "assistant-initial",
                    "label": "Un premier assistant, sur un usage précis",
                    "minimumAmount": 500,
                    "maximumAmount": 900,
                    "includes": [
                        "Un assistant testable sur votre cas réel",
                        "Des règles de validation humaine",
                        "Un écran pour surveiller les réponses"
                    ]
                },
                {
                    "key": "assistant-connecte",
                    "label": "Un assistant relié à vos données",
                    "minimumAmount": 1000,
                    "maximumAmount": 2000,
                    "includes": [
                        "L’assistant s’appuie sur vos documents ou votre catalogue",
                        "Des réponses sourcées et vérifiables",
                        "Un suivi de la qualité des réponses"
                    ]
                }
            ],
            "options": [
                {
                    "key": "canal-site",
                    "label": "Disponible directement sur votre site",
                    "minimumAmount": 200,
                    "maximumAmount": 450
                },
                {
                    "key": "reponses-email",
                    "label": "Préparation de vos réponses email",
                    "minimumAmount": 200,
                    "maximumAmount": 500
                },
                {
                    "key": "garde-fous",
                    "label": "Garde-fous renforcés et validation avant envoi",
                    "minimumAmount": 150,
                    "maximumAmount": 400
                }
            ]
        },
        {
            "key": "refonte",
            "label": "Remettre au propre un site existant",
            "summary": "On repart de ce qui existe pour le rendre plus clair et plus fiable.",
            "variants": [
                {
                    "key": "refonte-ciblee",
                    "label": "Corriger et moderniser l’existant",
                    "minimumAmount": 400,
                    "maximumAmount": 900,
                    "includes": [
                        "Un diagnostic clair de ce qui pose problème",
                        "Les corrections prioritaires appliquées",
                        "Une interface plus simple à utiliser"
                    ]
                }
            ],
            "options": [
                {
                    "key": "reprise-contenus",
                    "label": "Reprise et remise en forme des contenus",
                    "minimumAmount": 150,
                    "maximumAmount": 450
                },
                {
                    "key": "performance",
                    "label": "Site nettement plus rapide",
                    "minimumAmount": 150,
                    "maximumAmount": 400
                },
                {
                    "key": "accessibilite",
                    "label": "Accessibilité améliorée",
                    "minimumAmount": 200,
                    "maximumAmount": 500
                }
            ]
        },
        {
            "key": "outil-metier",
            "label": "Un outil sur mesure pour mon métier",
            "summary": "Une application pensée pour votre façon de travailler.",
            "variants": [
                {
                    "key": "outil-mvp",
                    "label": "Une première version utilisable",
                    "minimumAmount": 1200,
                    "maximumAmount": 2800,
                    "includes": [
                        "Les écrans indispensables à votre activité",
                        "Des accès distincts selon les personnes",
                        "Une mise en ligne et un accompagnement"
                    ]
                }
            ],
            "options": [
                {
                    "key": "espace-client",
                    "label": "Un espace réservé à vos clients",
                    "minimumAmount": 300,
                    "maximumAmount": 800
                },
                {
                    "key": "exports",
                    "label": "Exports et documents générés",
                    "minimumAmount": 150,
                    "maximumAmount": 450
                },
                {
                    "key": "suivi-activite",
                    "label": "Tableau de suivi de votre activité",
                    "minimumAmount": 200,
                    "maximumAmount": 600
                }
            ]
        }
    ],
    "adjustments": {
        "priorityDelay": {
            "label": "Délai prioritaire",
            "multiplier": 1.25
        },
        "contentWriting": {
            "label": "Rédaction des contenus",
            "minimumAmount": 150,
            "maximumAmount": 400
        }
    }
}
JSON;
}