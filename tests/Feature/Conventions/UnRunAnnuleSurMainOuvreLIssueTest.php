<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * `main-failure-issue.yml` ouvre une issue quand la CI ou la passe nocturne
 * ne passe pas sur main, parce que la promotion d'un tag exige l'une et l'autre
 * vertes sur son commit. Il ne regardait que les runs en échec. Or un job qui
 * dépasse son `timeout-minutes` est annulé, pas en échec : du 16 au 24/10, la
 * part App\Services de la passe nocturne a été annulée à sa limite chaque nuit,
 * aucune issue ne s'est ouverte, et le blocage de la livraison ne s'est vu que
 * le jour de la release.
 *
 * Cette garde exige qu'un run annulé ouvre l'issue comme un run en échec, que
 * les jobs annulés y soient nommés, que l'issue dise qu'il a été annulé, et que
 * seul un run annulé sans aucun job (remplacé dans son groupe de concurrence
 * avant d'avoir commencé) n'ouvre rien. Elle exige aussi un titre borné : sept
 * parts annulées, aux noms de matrice longs de cent caractères, dépassent les
 * 256 caractères d'un titre d'issue, et GitHub refuserait l'issue.
 */

/**
 * Le job qui ouvre l'issue, tel que GitHub le lit.
 *
 * @return array<mixed>
 */
function signalementDuRougeLeJob(): array
{
    $job = data_get(Yaml::parseFile(base_path('.github/workflows/main-failure-issue.yml')), 'jobs.signaler');

    if (! is_array($job)) {
        throw new RuntimeException('main-failure-issue.yml ne déclare plus le job « signaler » : la garde ne sait plus quoi lire.');
    }

    return $job;
}

/**
 * L'étape qui ouvre ou complète l'issue.
 *
 * @return array<mixed>
 */
function signalementDuRougeLEtape(): array
{
    foreach ((array) (signalementDuRougeLeJob()['steps'] ?? []) as $etape) {
        if (is_array($etape) && is_string($etape['run'] ?? null) && str_contains($etape['run'], 'gh issue create')) {
            return $etape;
        }
    }

    throw new RuntimeException('main-failure-issue.yml n’a plus d’étape qui ouvre l’issue : la garde ne sait plus quoi lire.');
}

/**
 * Le script de cette étape.
 */
function signalementDuRougeLeScript(): string
{
    $script = signalementDuRougeLEtape()['run'] ?? null;

    assert(is_string($script));

    return $script;
}

it('se lance pour un run annulé comme pour un run en échec, sans autre condition', function (): void {
    expect(signalementDuRougeLeJob()['if'] ?? null)
        ->toBe("github.event.workflow_run.conclusion == 'failure' || github.event.workflow_run.conclusion == 'cancelled'")
        ->and(signalementDuRougeLEtape())->not->toHaveKey('if');
});

it('nomme les jobs annulés avec ceux en échec', function (): void {
    expect(signalementDuRougeLeScript())
        ->toContain('select(.conclusion == "failure" or .conclusion == "cancelled")')
        ->toContain('if .conclusion == "cancelled" then "\(.name) (annule)" else .name end')
        ->toContain('JOBS=$(jq -r \'join(", ")\' <<<"$NOMS")')
        ->toContain('| jobs en echec ou annules | **$JOBS** |');
});

it('dit dans l’issue que le run a été annulé', function (): void {
    $script = signalementDuRougeLeScript();

    expect(data_get(signalementDuRougeLEtape(), 'env.CONCLUSION'))->toBe('${{ github.event.workflow_run.conclusion }}')
        ->and(preg_match('/if \[ "\$CONCLUSION" = "cancelled" \]; then\s+ETAT_DU_RUN="a ete annule sur [^"]*"\s+else\s+ETAT_DU_RUN="a echoue sur [^"]*"\s+fi/', $script))->toBe(1)
        ->and($script)->toContain('**$WORKFLOW** $ETAT_DU_RUN');
});

it('ne se tait que pour un run annulé sans aucun job', function (): void {
    $script = signalementDuRougeLeScript();

    expect(substr_count($script, 'exit '))->toBe(1)
        ->and(preg_match('/if \[ "\$CONCLUSION" = "cancelled" \] && \[ "\$NOMBRE" -eq 0 \]; then\n[^\n]*\n\s*exit 0\n\s*fi/', $script))->toBe(1)
        ->and($script)->toContain('NOMBRE=$(jq \'length\' <<<"$NOMS")');
});

it('borne le titre de l’issue en nommant le premier job et en comptant les autres', function (): void {
    $script = signalementDuRougeLeScript();

    expect($script)
        ->toContain('EN_TITRE="$(jq -r \'.[0]\' <<<"$NOMS") et $((NOMBRE - 1)) autre(s)"')
        ->toContain('--title "ci: $WORKFLOW est rouge sur main — $EN_TITRE"');

    expect(str_contains($script, '--title "ci: $WORKFLOW est rouge sur main — $JOBS"'))->toBeFalse();
});
