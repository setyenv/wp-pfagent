<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework;

/**
 * Post-processing filter applied to the LLM's final text reply BEFORE it
 * reaches the user.
 *
 * Three responsibilities:
 *
 * 1. Jargon detection — engineering terms / provider control tokens that
 *    must never reach a customer reply. When found, the loop is told to
 *    re-prompt with a list of the specific terms the model used.
 *
 * 2. Provider-marker detection — DeepSeek / OpenAI internal framing tokens
 *    that occasionally leak as text (`<｜｜DSML｜｜tool_calls>`,
 *    `<|tool_calls|>`, `<|fim_suffix|>`). Loop.php strips them; we also
 *    reject the reply so the model gets a fresh attempt instead of the
 *    customer seeing the leftover.
 *
 * 3. Permission-asking / hedging detection — replies that end with
 *    "¿quieres que…?", "Si quieres puedo…", "indícamelo si…", "Should I…",
 *    etc.
 *
 * The analyse() method returns:
 *   - ok: whether the reply is acceptable
 *   - reason: forbidden_terms | permission_asking | ''
 *   - found: list of every offending substring or pattern (NOT just the
 *     first — directives are stronger when they enumerate every problem
 *     at once so the LLM doesn't whack-a-mole one at a time)
 *   - directive: a system-reminder phrasing that names the offending
 *     fragments and gives a concrete declarative rewrite example so the
 *     model has something to imitate instead of having to invent one.
 *
 * The filter is provider-agnostic and host-configurable. Default rules in
 * `defaults()` are deliberately broad; hosts override by passing custom
 * forbidden terms and permission patterns to the constructor.
 */
final class OutputFilter
{
    /**
     * @param list<string> $forbiddenTerms case-insensitive substrings to forbid
     * @param list<string> $permissionPatterns regex patterns (without delimiters) that match permission-asking endings
     */
    public function __construct(
        private readonly array $forbiddenTerms = [],
        private readonly array $permissionPatterns = [],
    ) {
    }

    public static function defaults(): self
    {
        // Defaults target Spanish + English permission-asking, the common
        // engineering jargon we saw leaking in the marathon, and the
        // provider-internal control tokens DeepSeek / OpenAI sometimes
        // exude. Hosts add their own product-specific terms (plugin slugs,
        // internal table names, etc.) by re-instantiating with extended
        // lists.
        return new self(
            forbiddenTerms: [
                // ── Engineering jargon ──────────────────────────────────
                'VFS', 'vfs',
                'webhook', 'webhooks',
                'hook de WordPress', 'hook',
                'API REST', 'endpoint',
                'JSON schema', 'JSON Schema', 'json_schema',
                'response_format', 'tool_choice',
                'compile', 'compile error', 'compila',
                'workflow draft', 'draft', 'borrador',
                'workspace', 'pfflow',
                'plugin', 'plugins',
                'wp-pfmanagement', 'wp-pfworkflow', 'wp-pfagent',
                'pfmanagement', 'pfworkflow', 'pfagent',
                'incidencia técnica', 'error interno del plugin', 'logs de error',
                'servicio del plugin', 'tipos de datos', 'argumento',
                // ── Provider-internal control markers ───────────────────
                'DSML', 'system_reminder',
                '<|tool_calls|>', '<|fim_prefix|>', '<|fim_middle|>', '<|fim_suffix|>',
                '<|im_start|>', '<|im_end|>', '<|endoftext|>',
                // ── Framework leaks ─────────────────────────────────────
                '[system-reminder]', 'OutputFilter',
                // ── pfagent UI internals (subject to change) ────────────
                // The preview / preview panel is an implementation detail
                // of the wp-pfagent chat UI that is being redesigned. The
                // LLM must not promise things "están en el panel de vista
                // previa" because (a) the customer often isn't even
                // looking at that surface and (b) the surface name will
                // not survive the rewrite. Same goes for the Apply button,
                // the chat UI itself, and any synonym for "preview".
                'panel de vista previa', 'vista previa', 'previsualización', 'previsualizacion',
                'preview panel', 'panel preview', 'preview',
                'panel de previsualización', 'panel de previsualizacion',
                'interfaz de chat', 'chat interface', 'panel de chat',
                // 'apply' / 'aplica' on its own caused false-positive
                // rewrites (the verb is too common in legitimate Spanish);
                // only ban the specific button reference.
                'botón Apply', 'boton Apply', 'el Apply', 'Apply button',
                // ── Internal data-wiring syntax leaks ──────────────────
                // Workflow source uses template-style data refs to wire
                // node outputs into downstream node inputs:
                //   {{node.record_create_2.record.record.nombre}}
                //   ${event.record.field}
                //   node.<id>.<pin>
                // These are implementation details of the visual editor
                // / source DSL. WS11 (live) ended with a literal
                // {{node.record_create_2.record.record.nombre}} pasted to
                // the customer as "para personalizar el aviso". The
                // workflow itself is fine; the LEAK to the customer is
                // not. Treat the prefixes as jargon.
                '{{node.', '${event.', '${node.', '${trigger.',
                'node.record_', 'node.action_', 'node.trigger_',
            ],
            permissionPatterns: [
                // ── Hard rule #1: no question marks in a final reply ────
                '[?¿]',

                // ── Hard rule #2: provider control markers as plain text ─
                // Regex note: under /u flag, `\xEF` means codepoint U+00EF
                // (ï), NOT the raw UTF-8 byte 0xEF. To match U+FF5C (｜)
                // we MUST use `\x{ff5c}`. Earlier versions had a silent
                // mismatch here.
                '<\x{ff5c}',                                      // <｜
                '<\\|(?:tool_calls|fim_|im_start|im_end|endoftext)',

                // ── Permission / hedging: "if you want me to" family ────
                // Curated post-probe: drop the patterns that false-positive
                // on legitimate informative closings ("Dime qué necesitas
                // hacer y arranco" after a successful inventory).
                'Si\\s+(quieres|prefieres|necesitas|deseas|me\\s+das|lo\\s+prefieres|te\\s+parece)',
                'Dime\\s+si\\s+(quieres|prefieres|necesitas|deseas)',
                'Indícame(lo)?\\s+(si|cuando|cuál|qué|cómo)\\b',
                'Indica(me|melo)\\s+(si|cuando|cuál|qué|cómo)\\b',
                'Avísame\\s+(si|cuando)',
                'Pregúntame\\b',
                'Necesito\\s+que\\s+me\\s+(digas|confirmes|indiques)',
                'Para\\s+continuar,?\\s+(necesito|dime)',
                '¿\\s*Te\\s+parece\\b',

                // "Puedo X, Y o Z" — offering a menu of next actions
                // disguised as a statement. The user just asked for one
                // thing; we should have done it (or stated we cannot),
                // not pivoted into a maitre-d' role with three options.
                // Triggers when "puedo" appears in the SAME sentence as
                // an "o" (or) and at least two action verbs in infinitive.
                '\\bpuedo\\s+\\w+(?:[a-záéíóúñ]+)?\\s+[^.]{0,200}\\so\\s+\\w+(?:[a-záéíóúñ]+)?\\b',
                // "Si necesitas X" / "Cuando quieras X" — same family;
                // the rewritten message keeps inviting follow-up.
                'Cuando\\s+(quieras|necesites|prefieras)\\b',
                // "El siguiente paso (natural|lógico|sería)..." — soft
                // permission-asking suggesting the next move; M16/M19/M20
                // live runs ended with this. The customer did not ask for
                // a roadmap, just the result.
                'El\\s+siguiente\\s+paso',
                'Los?\\s+siguientes?\\s+pasos?',
                'El\\s+paso\\s+(natural|lógico|recomendado|siguiente)',

                // ── English equivalents
                'Just\\s+let\\s+me\\s+know',
                'Should\\s+I\\s+(continue|proceed|add|do|create|delete|set\\s+up|configure)',
                'Do\\s+you\\s+want\\s+me\\s+to',
                'Would\\s+you\\s+like\\s+me\\s+to',
                'Let\\s+me\\s+know\\s+if\\s+you',
                'Shall\\s+I\\b',
            ],
        );
    }

    /**
     * Analyse a final assistant text reply.
     *
     * @return array{ok: bool, reason: string, found: list<string>, directive: string}
     */
    public function analyse(string $text): array
    {
        // Collect all forbidden terms first; if any hit, surface the FULL
        // list so the directive can enumerate every offence (a single
        // directive is more effective than four rounds of one-at-a-time).
        $lowered = mb_strtolower($text);
        $foundTerms = [];
        foreach ($this->forbiddenTerms as $term) {
            if ($term === '') {
                continue;
            }
            if (str_contains($lowered, mb_strtolower($term))) {
                $foundTerms[] = $term;
            }
        }
        if ($foundTerms !== []) {
            $unique = array_values(array_unique($foundTerms));
            // Written in English like every other instruction the model gets:
            // a directive in one language is also an instruction to answer in
            // that language, and this one fires mid-turn, right before the
            // rewrite. Which language the customer gets is theirs to decide,
            // never a side effect of a vocabulary check.
            $directive = sprintf(
                "Your last reply used words the customer should never see: %s. "
                . "Rewrite it in product language — no jargon, no plugin names, no provider markers. "
                . "Keep the substance and keep the language you were already writing in; change only the vocabulary.",
                implode(', ', array_map(static fn(string $t) => '"' . $t . '"', $unique)),
            );
            return ['ok' => false, 'reason' => 'forbidden_terms', 'found' => $unique, 'directive' => $directive];
        }

        // Collect every permission pattern that matches AND the literal
        // substring that triggered it. The LLM gets a much clearer signal
        // when it sees "your text said 'Si necesitas ayuda' here" than
        // when it sees an opaque regex.
        $matches = [];
        foreach ($this->permissionPatterns as $pattern) {
            if (@preg_match('/' . $pattern . '/iu', $text, $m) === 1) {
                $matches[] = $m[0];
            }
        }
        if ($matches !== []) {
            $uniq = array_values(array_unique($matches));
            $samples = array_slice($uniq, 0, 4);
            $directive = sprintf(
                "Your last reply is still asking for permission or uses markers. Specifically wrong: %s. "
                . "Rewrite it in DECLARATIVE VOICE — state what you did or what is, do not offer, do not ask. "
                . "Examples of a CORRECT closing: \"I created X.\" / \"They are available for review.\" / \"Done, this is finished.\" / \"There is no way to do Y; drop it or try Z.\". "
                . "Examples of an INCORRECT closing: \"Would you like me to do it?\" / \"Let me know if you need help\" / \"Tell me if X\" / \"Should I proceed?\". "
                . "The user knows they can talk to you again if they need anything else; do NOT remind them at the end.",
                implode(', ', array_map(static fn(string $t) => '"' . $t . '"', $samples)),
            );
            return ['ok' => false, 'reason' => 'permission_asking', 'found' => $uniq, 'directive' => $directive];
        }

        return ['ok' => true, 'reason' => '', 'found' => [], 'directive' => ''];
    }
}
