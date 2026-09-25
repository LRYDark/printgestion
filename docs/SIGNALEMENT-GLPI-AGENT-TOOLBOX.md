# Signalement à préparer : la ToolBox de GLPI Agent ne lance pas sa tâche toute seule

Ce fichier garde les faits mesurés chez un client le 25/09/2026, et le texte prêt à publier chez Teclib'. Il
explique aussi **pourquoi le plugin appuie lui-même sur « Run task »** : quiconque lira ce code dans six mois se
demandera si ce n'était pas du zèle.

## Ce qui a été mesuré

Poste Windows, GLPI Agent 1.19 (MSI), ToolBox activée par le plugin (`etc/toolbox.yaml` + `toolbox-plugin.local`),
tâche `netscan` `enabled: yes`, cadence `delay: 1d`, jamais lancée. Service relancé juste après l'écriture.

| Essai | Service relancé | Premier scan | Écart |
|---|---|---|---|
| 1 | 18:38:57 | 18:44 environ | 5 min 30 |
| 2 | 20:59:09 | 21:13:13 | **14 min 04**, à la seconde où la page `/toolbox/inventory` a été ouverte |
| 3 (avec « Run task ») | 21:34:36 | 21:34:40 | **1 seconde** |

Journal de l'agent, essai 2 :

```
[21:34:36] [http server] HTTPD ToolBox Server plugin loaded
   … rien pendant quatorze minutes …
[21:13:13] Starting printgestion-imprimantes network scan task job...
```

## Ce que dit le code officiel

`GLPI::Agent::HTTP::Server::ToolBox::Inventory::_get_next_run_date()` : pour une tâche jamais lancée
(`last_run_date` absent), la date calculée tombe dans le passé, et elle est ramenée à maintenant avec un étalement
de moins d'une minute.

```perl
if ($next_start + $fuzzy < $now) {
    $next_start = $now;
    $fuzzy      = int(rand($delay > 60 ? 60 : $delay));
}
```

`GLPI::Agent::Daemon::sleep()` appelle `handleRequests()` à chaque tour de boucle (une seconde), et le bloc des
minuteries de plugins y est évalué **avant** d'accepter la moindre connexion. Sur le papier : premier scan dans la
minute, sans requête HTTP. Ce n'est pas ce qu'on observe.

## Le contournement retenu

Le fichier d'installation envoie lui-même le formulaire du bouton « Run task » de la ToolBox, juste après la
relance du service (`Agentdeploy::getToolboxRunNowBody()`) :

```
POST http://127.0.0.1:62354/toolbox/inventory
submit%2Frun-now=1&checkbox%2Fprintgestion-imprimantes=on
```

`Inventory::_submit_runnow()` appelle `netscan()` immédiatement, puis reprogramme la cadence normale : c'est
exactement le bouton de l'interface. Six essais espacés de cinq secondes, le temps que le port se rouvre. Un échec
ne casse rien — le scan partira à sa cadence, comme avant.

## Texte prêt à publier (en anglais)

> **ToolBox netscan job does not start until an HTTP request reaches the agent**
>
> **Version:** GLPI Agent 1.19 (Windows MSI), ToolBox plugin enabled through `etc/toolbox.yaml` and
> `toolbox-plugin.local`.
>
> **Setup:** a `netscan` job, `enabled: yes`, `delay` scheduling of `1d`, written by an automated installer and
> followed by a service restart. The job has never run (no `last_run_date`).
>
> **Expected:** per `Inventory::_get_next_run_date()`, a job that has never run gets `$next_start = $now` with
> `$fuzzy = int(rand(60))`, so the first scan should start within a minute of the plugin being loaded. And
> `Daemon::sleep()` calls `handleRequests()` once per second, with the plugin timer block evaluated before any
> connection is accepted.
>
> **Observed, twice on the same host:**
>
> ```
> [21:34:36] [http server] HTTPD ToolBox Server plugin loaded
> … nothing for 14 minutes …
> [21:13:13] Starting printgestion-imprimantes network scan task job...
> ```
>
> The first run started 5 min 30 after the service start in one case, and 14 min 04 in another — the latter at the
> exact second a browser was pointed at `http://127.0.0.1:62354/toolbox/inventory`. With no HTTP traffic at all,
> the job did not appear to start.
>
> **Question:** is the plugin timer (`timer_event()` / `events_cb()`) guaranteed to be evaluated when no connection
> is pending? On this host it behaves as if the schedule is only re-evaluated when a request arrives.
>
> **Workaround in use:** posting the ToolBox's own "Run task" form (`submit/run-now` + `checkbox/<job>`) to
> `/toolbox/inventory` right after the restart. The job then starts within one second, every time.

## Avant de publier

- Confirmer la version exacte : `"C:\Program Files\GLPI-Agent\glpi-agent.bat" --version`, et la citer telle quelle.
- Vérifier si le même poste, laissé au repos sans aucune requête sur le port 62354, finit par lancer la tâche : si
  oui, dire au bout de combien de temps. C'est la question qui décide entre « minuterie jamais évaluée » et
  « minuterie évaluée trop rarement ».
