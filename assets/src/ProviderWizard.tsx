import { __, _n, sprintf } from '@wordpress/i18n';
import { Cog, Loader2, RefreshCw, ShieldCheck, Trash2, X } from 'lucide-react';
import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';

import {
  checkProviderHealth,
  deleteProviderCredential,
  getPermissionRules,
  listAgentTools,
  listProviderCredentials,
  listProviderModels,
  listProviderPresets,
  saveManualProviderModels,
  savePermissionRules,
  saveProviderCredential,
  saveProviderModels,
  testProviderCredential
} from './api';
import type {
  AgentToolContract,
  ProviderCredentialStatus,
  ProviderHealthResult,
  ProviderModel,
  ProviderModelCatalog,
  ProviderModelPricing,
  ProviderPreset,
  ProviderPresetCatalog
} from './types';

type PermissionVerdict = 'allow' | 'ask' | 'deny';

/**
 * Human-readable label + summary for every agent capability that can
 * be governed by a permission rule. The KEY is the internal tool name
 * (must match what the runtime registers) and the VALUE is what the
 * customer sees in the modal. Translators localise the value; never
 * the key.
 *
 * For any future tool not listed here the UI falls back to humanising
 * the internal name (handled by humaniseToolName below).
 */
function toolDisplay(name: string): { label: string; summary: string } {
  switch (name) {
    case 'list_files':
      return {
        label: __('Browse your workflow library', 'wp-pfagent'),
        summary: __('See the workflows that exist on this site. Read-only.', 'wp-pfagent'),
      };
    case 'read_file':
      return {
        label: __('Open a workflow to read it', 'wp-pfagent'),
        summary: __('Look inside a single workflow without making changes.', 'wp-pfagent'),
      };
    case 'write_file':
      return {
        label: __('Create or replace a workflow', 'wp-pfagent'),
        summary: __('Save a brand-new workflow or fully replace the contents of an existing one.', 'wp-pfagent'),
      };
    case 'edit_file':
      return {
        label: __('Edit part of a workflow', 'wp-pfagent'),
        summary: __('Change a specific section of an existing workflow without rewriting the rest.', 'wp-pfagent'),
      };
    case 'move_file':
      return {
        label: __('Rename a workflow', 'wp-pfagent'),
        summary: __('Change the name of a workflow as it appears in your library.', 'wp-pfagent'),
      };
    case 'delete_file':
      return {
        label: __('Delete a workflow', 'wp-pfagent'),
        summary: __('Remove a workflow from your library permanently.', 'wp-pfagent'),
      };
    case 'pfm_get_contract':
      return {
        label: __('Discover your data model', 'wp-pfagent'),
        summary: __('Look at which entities, fields and actions you have configured. Read-only.', 'wp-pfagent'),
      };
    case 'pfm_list':
      return {
        label: __('List records', 'wp-pfagent'),
        summary: __('Browse records (customers, orders, anything you store). Read-only.', 'wp-pfagent'),
      };
    case 'pfm_get':
      return {
        label: __('Open a record', 'wp-pfagent'),
        summary: __('Read the full content of a single record. Read-only.', 'wp-pfagent'),
      };
    case 'pfm_apply':
      return {
        label: __('Create or change records', 'wp-pfagent'),
        summary: __('Save new records or modify existing ones (customers, orders, configuration, etc.).', 'wp-pfagent'),
      };
    case 'create_variable':
      return {
        label: __('Declare a workflow variable', 'wp-pfagent'),
        summary: __('Define a new variable that workflows can read or write.', 'wp-pfagent'),
      };
    case 'activate_workflow':
      return {
        label: __('Activate a workflow', 'wp-pfagent'),
        summary: __('Turn on a draft workflow so it starts running when its trigger fires.', 'wp-pfagent'),
      };
    default:
      return {
        label: humaniseToolName(name),
        summary: __('Other capability — see this site\'s integrator for details.', 'wp-pfagent'),
      };
  }
}

function humaniseToolName(name: string): string {
  return name
    .replace(/[_\-.]+/g, ' ')
    .replace(/\b\w/g, (m) => m.toUpperCase());
}

function verdictFromRule(value: unknown): PermissionVerdict | 'advanced' {
  if (value === 'allow' || value === 'ask' || value === 'deny') return value;
  if (value && typeof value === 'object') return 'advanced';
  return 'ask';
}

function isVerdict(v: string): v is PermissionVerdict {
  return v === 'allow' || v === 'ask' || v === 'deny';
}

type ModelEditDraft = {
  contextLength?: string;
  maxOutputTokens?: string;
  defaultReasoningEffort?: string;
  pricing: {
    input?: string;
    output?: string;
    cacheRead?: string;
    cacheWrite?: string;
  };
};

interface ProviderWizardProps {
  onConfirm: (selection: { providerId: string; model: string }) => void;
  onCancel?: () => void;
  initialProviderId?: string;
  /** Fired after the operator confirms a credential delete. Host uses this
   *  to clear its own `active` state when the deleted credential was the
   *  one currently in use. */
  onCredentialDeleted?: (providerId: string) => void;
}

type Step = 'pick' | 'add' | 'model';

export function ProviderWizard({
  onConfirm,
  onCancel,
  initialProviderId,
  onCredentialDeleted
}: ProviderWizardProps) {
  // Always start at 'pick' so the user can swap provider on "Cambiar";
  // the initialProviderId is only used as a hint elsewhere.
  const [step, setStep] = useState<Step>('pick');
  const [credentials, setCredentials] = useState<ProviderCredentialStatus[]>([]);
  const [presets, setPresets] = useState<ProviderPresetCatalog | null>(null);
  const [providerId, setProviderId] = useState<string>(initialProviderId ?? '');
  const [models, setModels] = useState<ProviderModelCatalog | null>(null);
  const [selectedModel, setSelectedModel] = useState<string>('');
  const [manualModel, setManualModel] = useState<string>('');
  // Caps inputs collected in the model-pick step. Pre-populated from the
  // discovered model when the API exposed them; required from the operator
  // when it didn't. Either way these are persisted into the credential
  // BEFORE onConfirm so the runtime never hits "could not discover caps".
  const [confirmContextLength, setConfirmContextLength] = useState<string>('');
  const [confirmMaxOutput, setConfirmMaxOutput] = useState<string>('');
  const [confirming, setConfirming] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);

  // Add-form state
  const [addProviderId, setAddProviderId] = useState<string>('');
  const [addApiKey, setAddApiKey] = useState<string>('');
  const [addSettings, setAddSettings] = useState<Record<string, string>>({});

  // Per-row credential actions (test / delete / manage)
  const [testingProviderId, setTestingProviderId] = useState<string | null>(null);
  const [healthByProvider, setHealthByProvider] = useState<Record<string, ProviderHealthResult>>({});
  const [credentialDeleteTarget, setCredentialDeleteTarget] = useState<ProviderCredentialStatus | null>(null);
  const [deletingCredential, setDeletingCredential] = useState<boolean>(false);
  const [manageTarget, setManageTarget] = useState<ProviderCredentialStatus | null>(null);
  // Manage-modal local state
  const [manageBusy, setManageBusy] = useState<boolean>(false);
  const [manageError, setManageError] = useState<string>('');
  const [manageSettings, setManageSettings] = useState<Record<string, string>>({});
  const [manageApiKey, setManageApiKey] = useState<string>('');
  const [manageModels, setManageModels] = useState<ProviderModelCatalog | null>(null);
  const [manageManualInput, setManageManualInput] = useState<string>('');
  // Per-model draft overrides keyed by model id. The wizard hydrates these
  // from the credential's previously-saved models[] on Load so the user
  // doesn't have to retype pricing every time they reopen the modal.
  const [manageModelDrafts, setManageModelDrafts] = useState<Record<string, ModelEditDraft>>({});
  const [manageModelsSavedAt, setManageModelsSavedAt] = useState<string | null>(null);

  // Permission ruleset editor. The modal lets the operator pick a
  // verdict per capability (Allow / Ask / Deny) plus a default for any
  // capability not listed. Tools whose existing rule is a complex
  // pattern object (set via REST) are flagged "Custom" and preserved
  // verbatim on save — the simple UI never silently rewrites them.
  const [permsOpen, setPermsOpen] = useState<boolean>(false);
  const [permsBusy, setPermsBusy] = useState<boolean>(false);
  const [permsError, setPermsError] = useState<string>('');
  const [permsSavedAt, setPermsSavedAt] = useState<string>('');
  const [permsTools, setPermsTools] = useState<AgentToolContract[]>([]);
  const [permsDefault, setPermsDefault] = useState<PermissionVerdict>('ask');
  const [permsVerdictByTool, setPermsVerdictByTool] = useState<Record<string, PermissionVerdict>>({});
  const [permsAdvancedTools, setPermsAdvancedTools] = useState<Set<string>>(new Set());
  // Verbatim copy of the loaded rules object so we can round-trip
  // advanced patterns the simple UI does not represent.
  const [permsRawRules, setPermsRawRules] = useState<Record<string, unknown>>({});
  const [permsShowAdvanced, setPermsShowAdvanced] = useState<boolean>(false);
  const [permsAdvancedText, setPermsAdvancedText] = useState<string>('');

  const openPermissions = useCallback(async () => {
    setPermsOpen(true);
    setPermsBusy(true);
    setPermsError('');
    try {
      const [rulesRes, toolsRes] = await Promise.all([
        getPermissionRules(),
        listAgentTools(),
      ]);
      const rules = (rulesRes.rules ?? {}) as Record<string, unknown>;
      const tools = toolsRes.tools ?? [];

      const defaultRaw = rules['*'];
      setPermsDefault(typeof defaultRaw === 'string' && isVerdict(defaultRaw) ? defaultRaw : 'ask');

      const verdictByTool: Record<string, PermissionVerdict> = {};
      const advanced = new Set<string>();
      for (const tool of tools) {
        const raw = rules[tool.name];
        const v = verdictFromRule(raw);
        if (v === 'advanced') {
          advanced.add(tool.name);
        } else {
          verdictByTool[tool.name] = v;
        }
      }
      setPermsTools(tools);
      setPermsVerdictByTool(verdictByTool);
      setPermsAdvancedTools(advanced);
      setPermsRawRules(rules);
      setPermsAdvancedText(JSON.stringify(rules, null, 2));
      setPermsSavedAt(rulesRes.updatedAt ?? '');
    } catch (err) {
      setPermsError(messageOf(err));
    } finally {
      setPermsBusy(false);
    }
  }, []);

  const closePermissions = useCallback(() => {
    if (permsBusy) return;
    setPermsOpen(false);
    setPermsError('');
    setPermsShowAdvanced(false);
  }, [permsBusy]);

  const setVerdictForTool = useCallback((toolName: string, verdict: PermissionVerdict) => {
    setPermsVerdictByTool((prev) => ({ ...prev, [toolName]: verdict }));
  }, []);

  const savePermissionsClick = useCallback(async () => {
    setPermsBusy(true);
    setPermsError('');
    try {
      // Build the rules object the backend expects:
      //   1. Start from a copy of the raw rules so advanced patterns
      //      survive untouched.
      //   2. Overwrite the per-tool verdict for every simple entry the
      //      dropdowns set (advanced tools are not touched).
      //   3. Set the catch-all verdict.
      const next: Record<string, unknown> = { ...permsRawRules };
      for (const tool of permsTools) {
        if (permsAdvancedTools.has(tool.name)) continue;
        const v = permsVerdictByTool[tool.name] ?? 'ask';
        next[tool.name] = v;
      }
      next['*'] = permsDefault;
      const r = await savePermissionRules(next);
      const saved = (r.rules ?? {}) as Record<string, unknown>;
      setPermsRawRules(saved);
      setPermsAdvancedText(JSON.stringify(saved, null, 2));
      setPermsSavedAt(r.updatedAt ?? '');
    } catch (err) {
      setPermsError(messageOf(err));
    } finally {
      setPermsBusy(false);
    }
  }, [permsAdvancedTools, permsDefault, permsRawRules, permsTools, permsVerdictByTool]);

  // Power-user escape hatch: edit the raw JSON directly.
  const saveAdvancedClick = useCallback(async () => {
    setPermsBusy(true);
    setPermsError('');
    try {
      const parsed = JSON.parse(permsAdvancedText || '{}');
      if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
        throw new Error(__('The advanced JSON must be an object.', 'wp-pfagent'));
      }
      const r = await savePermissionRules(parsed as Record<string, unknown>);
      // Reload state from what the backend returned so the simple UI
      // re-renders with the new values.
      const rules = (r.rules ?? {}) as Record<string, unknown>;
      const verdictByTool: Record<string, PermissionVerdict> = {};
      const advanced = new Set<string>();
      for (const tool of permsTools) {
        const v = verdictFromRule(rules[tool.name]);
        if (v === 'advanced') advanced.add(tool.name);
        else verdictByTool[tool.name] = v;
      }
      const defaultRaw = rules['*'];
      setPermsDefault(typeof defaultRaw === 'string' && isVerdict(defaultRaw) ? defaultRaw : 'ask');
      setPermsVerdictByTool(verdictByTool);
      setPermsAdvancedTools(advanced);
      setPermsRawRules(rules);
      setPermsAdvancedText(JSON.stringify(rules, null, 2));
      setPermsSavedAt(r.updatedAt ?? '');
    } catch (err) {
      setPermsError(messageOf(err));
    } finally {
      setPermsBusy(false);
    }
  }, [permsAdvancedText, permsTools]);

  const refreshCredentials = useCallback(async () => {
    try {
      const catalog = await listProviderCredentials();
      setCredentials(catalog.credentials);
    } catch (err) {
      setError(messageOf(err));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      try {
        const [creds, presetCatalog] = await Promise.all([listProviderCredentials(), listProviderPresets()]);
        setCredentials(creds.credentials);
        setPresets(presetCatalog);
      } catch (err) {
        setError(messageOf(err));
      }
    })();
  }, []);

  const configuredCredentials = useMemo(
    () => credentials.filter((credential) => credential.configured),
    [credentials]
  );

  const handleTestCredential = useCallback(async (credential: ProviderCredentialStatus) => {
    if (testingProviderId !== null) return;
    setTestingProviderId(credential.providerId);
    setError('');
    try {
      const health = await checkProviderHealth(credential.providerId);
      setHealthByProvider((items) => ({ ...items, [credential.providerId]: health }));
      // Refresh credentials so the status badge reflects the new validated/failed state.
      await refreshCredentials();
    } catch (err) {
      setError(messageOf(err));
    } finally {
      setTestingProviderId(null);
    }
  }, [refreshCredentials, testingProviderId]);

  const handleConfirmCredentialDelete = useCallback(async () => {
    if (deletingCredential || !credentialDeleteTarget) return;
    setDeletingCredential(true);
    setError('');
    try {
      const deletedId = credentialDeleteTarget.providerId;
      await deleteProviderCredential(deletedId);
      await refreshCredentials();
      setHealthByProvider((items) => {
        const next = { ...items };
        delete next[deletedId];
        return next;
      });
      setCredentialDeleteTarget(null);
      // Notify the host so it can clear its own `active` state when the
      // deleted credential was the one in use — otherwise the UI keeps
      // showing the now-orphaned LLM ACTIVO chip pointing at nothing.
      if (onCredentialDeleted) {
        onCredentialDeleted(deletedId);
      }
    } catch (err) {
      setError(messageOf(err));
    } finally {
      setDeletingCredential(false);
    }
  }, [credentialDeleteTarget, deletingCredential, onCredentialDeleted, refreshCredentials]);

  // --- Manage modal ---
  const managePreset = useMemo<ProviderPreset | null>(() => {
    if (!manageTarget || !presets) return null;
    return presets.presets[manageTarget.providerId] ?? null;
  }, [manageTarget, presets]);
  const manageRequiredSettings = useMemo(
    () => (managePreset ? placeholdersFor(managePreset) : []),
    [managePreset]
  );

  const openManage = useCallback((credential: ProviderCredentialStatus) => {
    setManageTarget(credential);
    setManageSettings({});
    setManageApiKey('');
    setManageModels(null);
    setManageManualInput('');
    setManageError('');
    setManageModelDrafts({});
    setManageModelsSavedAt(null);
  }, []);

  const closeManage = useCallback(() => {
    if (manageBusy) return;
    setManageTarget(null);
    setManageSettings({});
    setManageApiKey('');
    setManageModels(null);
    setManageManualInput('');
    setManageError('');
    setManageModelDrafts({});
    setManageModelsSavedAt(null);
  }, [manageBusy]);

  const manageUpdateSetting = useCallback((key: string, value: string) => {
    setManageSettings((items) => ({ ...items, [key]: value }));
  }, []);

  const manageSaveSettings = useCallback(async () => {
    if (!manageTarget || manageBusy) return;
    if (manageApiKey.trim() === '') {
      setManageError(__('Re-enter the API key to save changes.', 'wp-pfagent'));
      return;
    }
    setManageBusy(true);
    setManageError('');
    try {
      const status = await saveProviderCredential(manageTarget.providerId, {
        apiKey: manageApiKey,
        settings: effectiveSettings(manageTarget, manageSettings),
      });
      setManageApiKey('');
      setManageSettings({});
      await refreshCredentials();
      setManageTarget(status);
    } catch (err) {
      setManageError(messageOf(err));
    } finally {
      setManageBusy(false);
    }
  }, [manageApiKey, manageBusy, manageSettings, manageTarget, refreshCredentials]);

  const manageLoadModels = useCallback(async (force: boolean) => {
    if (!manageTarget || manageBusy) return;
    setManageBusy(true);
    setManageError('');
    try {
      const catalog = await listProviderModels(manageTarget.providerId, { force });
      setManageModels(catalog);
      // Hydrate per-row edit drafts from anything the credential has already
      // saved (pricing the user typed before, caps when API was sparse, etc.).
      // Discovery is always the source of truth for what the provider currently
      // exposes; drafts overlay the user-owned bits on top.
      const saved = manageTarget.models ?? [];
      const drafts: Record<string, ModelEditDraft> = {};
      for (const m of catalog.models) {
        const previous = saved.find((s) => s.id === m.id);
        if (previous) {
          drafts[m.id] = draftFromSavedModel(previous, m);
        }
      }
      setManageModelDrafts(drafts);
      setManageModelsSavedAt(null);
    } catch (err) {
      setManageError(messageOf(err));
    } finally {
      setManageBusy(false);
    }
  }, [manageBusy, manageTarget]);

  const manageUpdateModelDraft = useCallback((modelId: string, patch: Partial<ModelEditDraft>) => {
    setManageModelDrafts((items) => {
      const previous = items[modelId] ?? { pricing: {} };
      return {
        ...items,
        [modelId]: {
          ...previous,
          ...patch,
          pricing: {
            ...previous.pricing,
            ...(patch.pricing ?? {}),
          },
        },
      };
    });
  }, []);

  const manageSaveModels = useCallback(async () => {
    if (!manageTarget || manageBusy || !manageModels) return;
    setManageBusy(true);
    setManageError('');
    try {
      const merged: ProviderModel[] = manageModels.models.map((model) => {
        const draft = manageModelDrafts[model.id];
        const next: ProviderModel = { ...model, source: 'mixed' };
        if (draft) {
          const contextLength = parseOptionalInt(draft.contextLength);
          if (contextLength !== undefined) next.contextLength = contextLength;
          const maxOut = parseOptionalInt(draft.maxOutputTokens);
          if (maxOut !== undefined) next.maxOutputTokens = maxOut;
          const pricing = buildPricing(model.pricing, draft.pricing);
          if (pricing !== undefined) next.pricing = pricing;
          const effort = (draft.defaultReasoningEffort ?? '').trim();
          if (effort !== '') {
            next.defaultReasoningEffort = effort;
          } else {
            // Empty / cleared: explicitly drop the previously-saved value
            // so the gateway falls back to provider default on the next turn.
            delete next.defaultReasoningEffort;
          }
        }
        return next;
      });
      const status = await saveProviderModels(manageTarget.providerId, merged);
      await refreshCredentials();
      setManageTarget(status);
      setManageModelsSavedAt(new Date().toISOString());
    } catch (err) {
      setManageError(messageOf(err));
    } finally {
      setManageBusy(false);
    }
  }, [manageBusy, manageModelDrafts, manageModels, manageTarget, refreshCredentials]);

  const manageSaveManual = useCallback(async () => {
    if (!manageTarget || manageBusy) return;
    const ids = manageManualInput
      .split(/\r?\n|,/)
      .map((s) => s.trim())
      .filter(Boolean);
    if (ids.length === 0) return;
    setManageBusy(true);
    setManageError('');
    try {
      const catalog = await saveManualProviderModels(manageTarget.providerId, ids);
      setManageModels(catalog);
      setManageManualInput('');
    } catch (err) {
      setManageError(messageOf(err));
    } finally {
      setManageBusy(false);
    }
  }, [manageBusy, manageManualInput, manageTarget]);

  const manageTestConnection = useCallback(async () => {
    if (!manageTarget || manageBusy) return;
    setManageBusy(true);
    setManageError('');
    try {
      const health = await checkProviderHealth(manageTarget.providerId);
      setHealthByProvider((items) => ({ ...items, [manageTarget.providerId]: health }));
      await refreshCredentials();
    } catch (err) {
      setManageError(messageOf(err));
    } finally {
      setManageBusy(false);
    }
  }, [manageBusy, manageTarget, refreshCredentials]);

  const fetchModels = useCallback(async (selectedProviderId: string) => {
    setBusy(true);
    setError('');
    try {
      const catalog = await listProviderModels(selectedProviderId);
      setModels(catalog);
      if (catalog.models.length > 0) {
        setSelectedModel(catalog.models[0].id);
      }
    } catch (err) {
      setError(messageOf(err));
    } finally {
      setBusy(false);
    }
  }, []);

  useEffect(() => {
    if (step === 'model' && providerId) {
      void fetchModels(providerId);
    }
  }, [step, providerId, fetchModels]);

  const handlePickCredential = (credential: ProviderCredentialStatus) => {
    setProviderId(credential.providerId);
    setSelectedModel('');
    setModels(null);
    setStep('model');
  };

  const handleAddSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!addProviderId || !addApiKey) {
      setError(__('Pick a provider and paste the API key.', 'wp-pfagent'));
      return;
    }
    setBusy(true);
    setError('');
    try {
      const settings = Object.fromEntries(
        Object.entries(addSettings).filter(([, value]) => value.trim() !== '')
      );
      await saveProviderCredential(addProviderId, {
        apiKey: addApiKey,
        ...(Object.keys(settings).length > 0 ? { settings } : {}),
      });
      // Best-effort connectivity check; ignore failure so unvalidated keys
      // still let the user proceed (some providers reject the discovery probe).
      try {
        await testProviderCredential(addProviderId);
      } catch {
        /* swallow */
      }
      await refreshCredentials();
      setProviderId(addProviderId);
      setAddApiKey('');
      setAddSettings({});
      setStep('model');
    } catch (err) {
      setError(messageOf(err));
    } finally {
      setBusy(false);
    }
  };

  // When the operator picks a model OR the discovery payload arrives,
  // pre-populate the confirm-step caps inputs from whatever the API
  // exposed. They're empty (and the inputs blank) when the provider's
  // /v1/models is sparse — that's the case where the operator MUST type
  // them in before the wizard lets them confirm.
  useEffect(() => {
    const finalModelId = (models?.models.length ?? 0) > 0 ? selectedModel : manualModel.trim();
    if (!finalModelId) {
      setConfirmContextLength('');
      setConfirmMaxOutput('');
      return;
    }
    // Prefer values the credential already has saved (the operator may
    // have configured this model before in the Manage modal).
    const saved = credentials.find((c) => c.providerId === providerId)?.models?.find((m) => m.id === finalModelId);
    const discovered = (models?.models ?? []).find((m) => m.id === finalModelId);
    const ctx = saved?.contextLength ?? discovered?.contextLength;
    const out = saved?.maxOutputTokens ?? discovered?.maxOutputTokens;
    setConfirmContextLength(typeof ctx === 'number' && ctx > 0 ? String(ctx) : '');
    setConfirmMaxOutput(typeof out === 'number' && out > 0 ? String(out) : '');
  }, [selectedModel, manualModel, models, credentials, providerId]);

  const handleConfirmModel = async () => {
    const finalModel = (models?.models.length ?? 0) > 0 ? selectedModel : manualModel.trim();
    if (!providerId || !finalModel) {
      setError(__('Pick a model or enter the id manually.', 'wp-pfagent'));
      return;
    }
    const ctxNum = Number.parseInt(confirmContextLength, 10);
    const outNum = Number.parseInt(confirmMaxOutput, 10);
    if (!Number.isFinite(ctxNum) || ctxNum <= 0 || !Number.isFinite(outNum) || outNum <= 0) {
      setError(__('Context length and max output tokens are required (greater than zero) before confirming the model.', 'wp-pfagent'));
      return;
    }

    setConfirming(true);
    setError('');
    try {
      // Build the model record to persist. Carry over whatever the API
      // discovery surfaced (capabilities, defaults, features, modalities,
      // ...) and override the two caps with what the operator just entered
      // — they always win because the API may have exposed nothing.
      const discovered = (models?.models ?? []).find((m) => m.id === finalModel);
      const presetEntry = presets?.presets[providerId];
      const family = (presetEntry as ProviderPreset | undefined)?.family ?? discovered?.family ?? '';
      const nextRecord: ProviderModel = {
        ...(discovered ?? { id: finalModel, label: finalModel, source: 'manual', family, capabilities: ['text_generation'] }),
        id: finalModel,
        family,
        source: 'mixed',
        contextLength: ctxNum,
        maxOutputTokens: outNum,
      };

      // Merge into the credential's existing models[] so previously-saved
      // entries (other models the operator has already configured) aren't
      // wiped.
      const existing = credentials.find((c) => c.providerId === providerId)?.models ?? [];
      const merged = [...existing.filter((m) => m.id !== finalModel), nextRecord];
      await saveProviderModels(providerId, merged);
      await refreshCredentials();
      onConfirm({ providerId, model: finalModel });
    } catch (err) {
      setError(messageOf(err));
    } finally {
      setConfirming(false);
    }
  };

  return (
    <section className="pfa-wizard" data-testid="provider-wizard">
      {error ? (
        <div className="pfa-banner pfa-banner--error" role="alert">
          <strong>{ __('Error:', 'wp-pfagent') }</strong> {error}
        </div>
      ) : null}

      {step === 'pick' ? (
        <div className="pfa-wizard__step">
          <h3>{ __('Credentials', 'wp-pfagent') }</h3>
          {configuredCredentials.length === 0 ? (
            <p className="pfa-wizard__hint">{ __('No credentials in the store yet. Add one to continue.', 'wp-pfagent') }</p>
          ) : (
            <ul className="pfa-wizard__credentials">
              {configuredCredentials.map((credential) => {
                const testing = testingProviderId === credential.providerId;
                return (
                  <li key={credential.providerId} className="pfa-wizard__credential-row">
                    <button type="button" className="pfa-wizard__credential" onClick={() => handlePickCredential(credential)}>
                      <strong>{credential.label}</strong>
                      <span className="pfa-wizard__credential-meta">
                        <span className="pfa-wizard__family">{credential.family}</span>
                        <span className={`pfa-wizard__status pfa-wizard__status--${credential.status}`}>{credentialStatusLabel(credential.status)}</span>
                        {credential.maskedKey ? <code>{credential.maskedKey}</code> : null}
                      </span>
                    </button>
                    <button
                      type="button"
                      className="pfa-wizard__credential-action"
                      onClick={() => void handleTestCredential(credential)}
                      disabled={testing || testingProviderId !== null}
                      aria-label={ sprintf(__('Test connection for %s', 'wp-pfagent'), credential.label) }
                      title={ __('Test connection', 'wp-pfagent') }
                    >
                      {testing ? <Loader2 size={14} className="pfa-spin" aria-hidden="true" /> : <ShieldCheck size={14} aria-hidden="true" />}
                    </button>
                    <button
                      type="button"
                      className="pfa-wizard__credential-action"
                      onClick={() => openManage(credential)}
                      disabled={testingProviderId !== null}
                      aria-label={ sprintf(__('Manage %s', 'wp-pfagent'), credential.label) }
                      title={ __('Manage credential', 'wp-pfagent') }
                    >
                      <Cog size={14} aria-hidden="true" />
                    </button>
                    <button
                      type="button"
                      className="pfa-wizard__credential-action pfa-wizard__credential-action--danger"
                      onClick={() => setCredentialDeleteTarget(credential)}
                      disabled={testingProviderId !== null}
                      aria-label={ sprintf(__('Delete credential %s', 'wp-pfagent'), credential.label) }
                      title={ __('Delete credential', 'wp-pfagent') }
                    >
                      <Trash2 size={14} aria-hidden="true" />
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
          <div className="pfa-wizard__actions">
            <button type="button" className="pfa-wizard__add" onClick={() => setStep('add')}>
              { __('+ Add new credential', 'wp-pfagent') }
            </button>
            <button type="button" className="pfa-wizard__cancel" onClick={() => void openPermissions()}>
              { __('Permissions', 'wp-pfagent') }
            </button>
            {onCancel ? (
              <button type="button" className="pfa-wizard__cancel" data-testid="wizard-cancel" onClick={onCancel}>
                { __('Cancel', 'wp-pfagent') }
              </button>
            ) : null}
          </div>
        </div>
      ) : null}

      {step === 'add' ? (
        <form className="pfa-wizard__step" onSubmit={(event) => void handleAddSubmit(event)}>
          <h3>{ __('New credential', 'wp-pfagent') }</h3>
          <label className="pfa-wizard__field">
            <span>{ __('Provider', 'wp-pfagent') }</span>
            <select
              value={addProviderId}
              onChange={(event) => setAddProviderId(event.target.value)}
              required
              disabled={busy}
            >
              <option value="">{ __('— Pick one —', 'wp-pfagent') }</option>
              {presets
                ? Object.entries(presets.presets).map(([id, preset]) => (
                    <option key={id} value={id}>
                      {(preset as ProviderPreset).label} ({preset.family})
                    </option>
                  ))
                : null}
            </select>
          </label>
          {(() => {
            const preset = addProviderId && presets ? presets.presets[addProviderId] ?? null : null;
            const required = preset ? placeholdersFor(preset) : [];
            return required.map((key) => (
              <label key={key} className="pfa-wizard__field">
                <span>{labelForSetting(key)}</span>
                <input
                  type="text"
                  value={addSettings[key] ?? ''}
                  onChange={(event) => setAddSettings((items) => ({ ...items, [key]: event.target.value }))}
                  placeholder={key === 'base_url' ? 'https://api.example.com/v1' : key}
                  disabled={busy}
                />
              </label>
            ));
          })()}
          <label className="pfa-wizard__field">
            <span>{ __('API key', 'wp-pfagent') }</span>
            <input
              type="password"
              value={addApiKey}
              onChange={(event) => setAddApiKey(event.target.value)}
              autoComplete="new-password"
              spellCheck={false}
              required
              disabled={busy}
              placeholder="sk-..."
            />
          </label>
          <p className="pfa-wizard__hint">{ __('The key is encrypted (AES-256-GCM) in the WordPress store and never returned over REST.', 'wp-pfagent') }</p>
          <div className="pfa-wizard__actions">
            <button type="submit" className="pfa-wizard__primary" disabled={busy}>
              {busy ? __('Saving…', 'wp-pfagent') : __('Save and continue', 'wp-pfagent')}
            </button>
            <button type="button" className="pfa-wizard__cancel" onClick={() => setStep('pick')} disabled={busy}>
              { __('Back', 'wp-pfagent') }
            </button>
          </div>
        </form>
      ) : null}

      {step === 'model' ? (
        <div className="pfa-wizard__step">
          <h3>{ __('Model', 'wp-pfagent') }</h3>
          <p className="pfa-wizard__hint">
            { __('Provider:', 'wp-pfagent') } <strong>{providerId}</strong>
          </p>
          {busy ? (
            <p>{ __('Loading models…', 'wp-pfagent') }</p>
          ) : models && models.models.length > 0 ? (
            <label className="pfa-wizard__field">
              <span>{ __('Model', 'wp-pfagent') }</span>
              <select value={selectedModel} onChange={(event) => setSelectedModel(event.target.value)}>
                {models.models.map((model: ProviderModel) => (
                  <option key={model.id} value={model.id}>
                    {model.label} {model.source === 'manual' ? __('(manual)', 'wp-pfagent') : ''}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <label className="pfa-wizard__field">
              <span>{ __('Model (manual)', 'wp-pfagent') }</span>
              <input
                type="text"
                value={manualModel}
                onChange={(event) => setManualModel(event.target.value)}
                placeholder={ __('model-id', 'wp-pfagent') }
              />
              {models && !models.manualAllowed ? (
                <small className="pfa-wizard__hint">
                  { __('This provider does not allow manual models; check your credential or retry.', 'wp-pfagent') }
                </small>
              ) : null}
            </label>
          )}
          <label className="pfa-wizard__field">
            <span>{ __('Context length (tokens)', 'wp-pfagent') }</span>
            <input
              type="number"
              min={1}
              step={1}
              value={confirmContextLength}
              onChange={(event) => setConfirmContextLength(event.target.value)}
              placeholder={ __('required — e.g. 128000', 'wp-pfagent') }
              disabled={confirming}
            />
          </label>
          <label className="pfa-wizard__field">
            <span>{ __('Max output (tokens)', 'wp-pfagent') }</span>
            <input
              type="number"
              min={1}
              step={1}
              value={confirmMaxOutput}
              onChange={(event) => setConfirmMaxOutput(event.target.value)}
              placeholder={ __('required — e.g. 8192', 'wp-pfagent') }
              disabled={confirming}
            />
          </label>
          <p className="pfa-wizard__hint">
            { __('Pre-filled when the provider API exposes caps; required otherwise. Stored on the credential so the runtime never has to guess.', 'wp-pfagent') }
          </p>
          <div className="pfa-wizard__actions">
            <button
              type="button"
              className="pfa-wizard__primary"
              disabled={busy || confirming || (!selectedModel && !manualModel.trim())}
              onClick={() => void handleConfirmModel()}
            >
              { confirming ? __('Saving…', 'wp-pfagent') : __('Confirm', 'wp-pfagent') }
            </button>
            <button
              type="button"
              className="pfa-wizard__cancel"
              onClick={() => {
                setStep('pick');
                setModels(null);
                setSelectedModel('');
              }}
              disabled={busy}
            >
              { __('Change provider', 'wp-pfagent') }
            </button>
            {onCancel ? (
              <button type="button" className="pfa-wizard__cancel" data-testid="wizard-cancel" onClick={onCancel} disabled={busy}>
                { __('Cancel', 'wp-pfagent') }
              </button>
            ) : null}
          </div>
        </div>
      ) : null}

      {credentialDeleteTarget ? (
        <div
          className="pfa-modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !deletingCredential) {
              setCredentialDeleteTarget(null);
            }
          }}
        >
          <div className="pfa-modal" role="dialog" aria-modal="true" aria-labelledby="pfa-cred-delete-title">
            <header className="pfa-modal__header">
              <div className="pfa-modal__icon pfa-modal__icon--danger" aria-hidden="true">
                <Trash2 size={16} />
              </div>
              <h2 id="pfa-cred-delete-title">{ __('Delete credential', 'wp-pfagent') }</h2>
            </header>
            <p className="pfa-modal__body">
              { sprintf(
                /* translators: %s: provider label */
                __('Credentials for "%s" will be removed from the encrypted store. This action cannot be undone.', 'wp-pfagent'),
                credentialDeleteTarget.label
              ) }
            </p>
            <footer className="pfa-modal__actions">
              <button
                type="button"
                className="pfa-wizard__cancel"
                onClick={() => setCredentialDeleteTarget(null)}
                disabled={deletingCredential}
              >
                { __('Cancel', 'wp-pfagent') }
              </button>
              <button
                type="button"
                className="pfa-modal__danger"
                onClick={() => void handleConfirmCredentialDelete()}
                disabled={deletingCredential}
                autoFocus
              >
                <Trash2 size={13} aria-hidden="true" />
                { deletingCredential ? __('Deleting…', 'wp-pfagent') : __('Delete', 'wp-pfagent') }
              </button>
            </footer>
          </div>
        </div>
      ) : null}

      {manageTarget ? (
        <div
          className="pfa-modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) {
              closeManage();
            }
          }}
        >
          <div className="pfa-modal pfa-modal--wide" role="dialog" aria-modal="true" aria-labelledby="pfa-manage-title">
            <header className="pfa-modal__header">
              <div className="pfa-modal__icon" aria-hidden="true">
                <Cog size={16} />
              </div>
              <h2 id="pfa-manage-title">{ sprintf(__('Manage %s', 'wp-pfagent'), manageTarget.label) }</h2>
              <button
                type="button"
                className="pfa-modal__close"
                onClick={closeManage}
                disabled={manageBusy}
                aria-label={ __('Close', 'wp-pfagent') }
              >
                <X size={16} />
              </button>
            </header>

            <div className="pfa-modal__body pfa-manage__body">
              <dl className="pfa-manage__summary">
                <dt>{ __('Provider', 'wp-pfagent') }</dt>
                <dd><code>{manageTarget.providerId}</code> · {manageTarget.family}</dd>
                <dt>{ __('Status', 'wp-pfagent') }</dt>
                <dd>
                  <span className={`pfa-wizard__status pfa-wizard__status--${manageTarget.status}`}>
                    {credentialStatusLabel(manageTarget.status)}
                  </span>
                </dd>
                <dt>{ __('Stored key', 'wp-pfagent') }</dt>
                <dd><code>{manageTarget.maskedKey ?? __('not stored', 'wp-pfagent')}</code></dd>
                {managePreset ? (
                  <>
                    <dt>{ __('Base URL', 'wp-pfagent') }</dt>
                    <dd><code>{managePreset.baseUrl}</code></dd>
                    <dt>{ __('Discovery', 'wp-pfagent') }</dt>
                    <dd>{managePreset.modelDiscovery}</dd>
                  </>
                ) : null}
                {manageTarget.validationMessage ? (
                  <>
                    <dt>{ __('Last validation', 'wp-pfagent') }</dt>
                    <dd>{manageTarget.validationMessage}</dd>
                  </>
                ) : null}
              </dl>

              {manageError ? (
                <div className="pfa-banner pfa-banner--error" role="alert">{manageError}</div>
              ) : null}

              <section className="pfa-manage__section">
                <h3>{ __('Credential', 'wp-pfagent') }</h3>
                <label className="pfa-wizard__field">
                  <span>{ __('New API key', 'wp-pfagent') }</span>
                  <input
                    type="password"
                    value={manageApiKey}
                    onChange={(event) => setManageApiKey(event.target.value)}
                    placeholder={manageTarget.maskedKey ?? __('Paste API key to confirm changes', 'wp-pfagent')}
                    autoComplete="new-password"
                    spellCheck={false}
                    disabled={manageBusy}
                  />
                </label>
                {manageRequiredSettings.map((key) => (
                  <label key={key} className="pfa-wizard__field">
                    <span>{labelForSetting(key)}</span>
                    <input
                      type="text"
                      value={manageSettings[key] ?? manageTarget.settings?.[key] ?? ''}
                      onChange={(event) => manageUpdateSetting(key, event.target.value)}
                      placeholder={key === 'base_url' ? 'https://api.example.com/v1' : key}
                      disabled={manageBusy}
                    />
                  </label>
                ))}
                <div className="pfa-manage__actions">
                  <button
                    type="button"
                    className="pfa-wizard__primary"
                    onClick={() => void manageSaveSettings()}
                    disabled={manageBusy || manageApiKey.trim() === ''}
                  >
                    {manageBusy ? <Loader2 size={13} className="pfa-spin" aria-hidden="true" /> : null}
                    { __('Save', 'wp-pfagent') }
                  </button>
                  <button
                    type="button"
                    className="pfa-wizard__cancel"
                    onClick={() => void manageTestConnection()}
                    disabled={manageBusy}
                  >
                    <ShieldCheck size={13} aria-hidden="true" /> { __('Test connection', 'wp-pfagent') }
                  </button>
                </div>
                {healthByProvider[manageTarget.providerId] ? (
                  <div className="pfa-manage__health" data-status={healthByProvider[manageTarget.providerId].status}>
                    <strong>{healthByProvider[manageTarget.providerId].status}</strong>
                    <span>{healthByProvider[manageTarget.providerId].message}</span>
                    {healthByProvider[manageTarget.providerId].errorType ? (
                      <em>{healthByProvider[manageTarget.providerId].errorType}</em>
                    ) : null}
                  </div>
                ) : null}
              </section>

              <section className="pfa-manage__section">
                <div className="pfa-manage__section-head">
                  <h3>{ __('Models', 'wp-pfagent') }</h3>
                  <div className="pfa-manage__actions">
                    <button
                      type="button"
                      className="pfa-wizard__cancel"
                      onClick={() => void manageLoadModels(false)}
                      disabled={manageBusy}
                    >
                      { __('Load', 'wp-pfagent') }
                    </button>
                    <button
                      type="button"
                      className="pfa-wizard__cancel"
                      onClick={() => void manageLoadModels(true)}
                      disabled={manageBusy}
                    >
                      <RefreshCw size={13} aria-hidden="true" /> { __('Refresh', 'wp-pfagent') }
                    </button>
                  </div>
                </div>
                {manageModels ? (
                  <>
                    <p className="pfa-wizard__hint">
                      { sprintf(
                        /* translators: 1: source, 2: ISO date, 3: count */
                        _n(
                          'Source: %1$s · fetched %2$s · %3$d model',
                          'Source: %1$s · fetched %2$s · %3$d models',
                          manageModels.models.length,
                          'wp-pfagent'
                        ),
                        manageModels.source,
                        manageModels.fetchedAt,
                        manageModels.models.length
                      ) }
                    </p>
                    {manageModels.metadata?.balance ? (
                      <p className="pfa-wizard__hint">
                        { sprintf(
                          /* translators: 1: total balance, 2: currency, 3: topped-up amount, 4: granted amount */
                          __('Balance: %1$s %2$s (topped up %3$s · granted %4$s)', 'wp-pfagent'),
                          manageModels.metadata.balance.total,
                          manageModels.metadata.balance.currency,
                          manageModels.metadata.balance.toppedUp,
                          manageModels.metadata.balance.granted
                        ) }
                      </p>
                    ) : null}
                    {manageModels.models.length > 0 ? (
                      <>
                        <ul className="pfa-manage__models">
                          {manageModels.models.slice(0, 50).map((model: ProviderModel) => {
                            const draft = manageModelDrafts[model.id] ?? { pricing: {} };
                            const apiCtx = model.contextLength;
                            const apiOut = model.maxOutputTokens;
                            return (
                              <li key={`${model.source}-${model.id}`}>
                                <strong>{model.label}</strong>
                                <code>{model.id}</code>
                                <em>{model.source}</em>
                                {model.description ? (
                                  <p className="pfa-wizard__hint">{model.description}</p>
                                ) : null}
                                <div className="pfa-wizard__field-grid">
                                  <label className="pfa-wizard__field">
                                    <span>{ __('Context length (tokens)', 'wp-pfagent') }</span>
                                    <input
                                      type="number"
                                      min={0}
                                      step={1}
                                      value={draft.contextLength ?? (apiCtx ? String(apiCtx) : '')}
                                      onChange={(event) => manageUpdateModelDraft(model.id, { contextLength: event.target.value })}
                                      placeholder={apiCtx ? String(apiCtx) : __('e.g. 128000 — fill in manually', 'wp-pfagent')}
                                      disabled={manageBusy}
                                    />
                                  </label>
                                  <label className="pfa-wizard__field">
                                    <span>{ __('Max output (tokens)', 'wp-pfagent') }</span>
                                    <input
                                      type="number"
                                      min={0}
                                      step={1}
                                      value={draft.maxOutputTokens ?? (apiOut ? String(apiOut) : '')}
                                      onChange={(event) => manageUpdateModelDraft(model.id, { maxOutputTokens: event.target.value })}
                                      placeholder={apiOut ? String(apiOut) : __('e.g. 8192 — fill in manually', 'wp-pfagent')}
                                      disabled={manageBusy}
                                    />
                                  </label>
                                  <label className="pfa-wizard__field">
                                    <span>{ __('Default reasoning effort', 'wp-pfagent') }</span>
                                    {model.reasoningVariants && model.reasoningVariants.length > 0 ? (
                                      <select
                                        value={draft.defaultReasoningEffort ?? ''}
                                        onChange={(event) => manageUpdateModelDraft(model.id, { defaultReasoningEffort: event.target.value })}
                                        disabled={manageBusy}
                                      >
                                        <option value="">{ __('— none —', 'wp-pfagent') }</option>
                                        {model.reasoningVariants.map((variant) => (
                                          <option key={variant} value={variant}>{variant}</option>
                                        ))}
                                      </select>
                                    ) : (
                                      <input
                                        type="text"
                                        value={draft.defaultReasoningEffort ?? ''}
                                        onChange={(event) => manageUpdateModelDraft(model.id, { defaultReasoningEffort: event.target.value })}
                                        placeholder={ __('low | medium | high | max', 'wp-pfagent') }
                                        disabled={manageBusy}
                                      />
                                    )}
                                  </label>
                                  <label className="pfa-wizard__field">
                                    <span>{ __('Input $/Mtok', 'wp-pfagent') }</span>
                                    <input
                                      type="number"
                                      min={0}
                                      step="0.001"
                                      value={draft.pricing.input ?? ''}
                                      onChange={(event) => manageUpdateModelDraft(model.id, { pricing: { input: event.target.value } })}
                                      placeholder="0.00"
                                      disabled={manageBusy}
                                    />
                                  </label>
                                  <label className="pfa-wizard__field">
                                    <span>{ __('Output $/Mtok', 'wp-pfagent') }</span>
                                    <input
                                      type="number"
                                      min={0}
                                      step="0.001"
                                      value={draft.pricing.output ?? ''}
                                      onChange={(event) => manageUpdateModelDraft(model.id, { pricing: { output: event.target.value } })}
                                      placeholder="0.00"
                                      disabled={manageBusy}
                                    />
                                  </label>
                                  <label className="pfa-wizard__field">
                                    <span>{ __('Cache read $/Mtok', 'wp-pfagent') }</span>
                                    <input
                                      type="number"
                                      min={0}
                                      step="0.001"
                                      value={draft.pricing.cacheRead ?? ''}
                                      onChange={(event) => manageUpdateModelDraft(model.id, { pricing: { cacheRead: event.target.value } })}
                                      placeholder="0.00"
                                      disabled={manageBusy}
                                    />
                                  </label>
                                  <label className="pfa-wizard__field">
                                    <span>{ __('Cache write $/Mtok', 'wp-pfagent') }</span>
                                    <input
                                      type="number"
                                      min={0}
                                      step="0.001"
                                      value={draft.pricing.cacheWrite ?? ''}
                                      onChange={(event) => manageUpdateModelDraft(model.id, { pricing: { cacheWrite: event.target.value } })}
                                      placeholder="0.00"
                                      disabled={manageBusy}
                                    />
                                  </label>
                                </div>
                                {(model.features && Object.keys(model.features).length > 0)
                                  || (model.reasoningVariants && model.reasoningVariants.length > 0)
                                  || model.modalities
                                  || model.equivalentSnapshot
                                  || (model.capabilities && model.capabilities.length > 0 && model.capabilities.join('') !== 'text_generation') ? (
                                  <p className="pfa-wizard__hint">
                                    {model.features ? (
                                      <>
                                        { __('Capabilities:', 'wp-pfagent') }{' '}
                                        {Object.entries(model.features)
                                          .filter(([, supported]) => supported)
                                          .map(([flag]) => flag)
                                          .join(', ') || __('none', 'wp-pfagent')}
                                      </>
                                    ) : null}
                                    {model.reasoningVariants && model.reasoningVariants.length > 0 ? (
                                      <>
                                        {' · '}{ __('Reasoning:', 'wp-pfagent') }{' '}{model.reasoningVariants.join(', ')}
                                      </>
                                    ) : null}
                                    {model.modalities ? (
                                      <>
                                        {' · '}{ __('Modalities:', 'wp-pfagent') }{' in='}{(model.modalities.request || []).join(',') || '—'}{' out='}{(model.modalities.response || []).join(',') || '—'}
                                      </>
                                    ) : null}
                                    {model.defaults ? (
                                      <>
                                        {' · '}{ __('Defaults:', 'wp-pfagent') }{' '}
                                        {Object.entries(model.defaults)
                                          .map(([k, v]) => `${k}=${v}`)
                                          .join(', ')}
                                      </>
                                    ) : null}
                                    {model.equivalentSnapshot ? (
                                      <>
                                        {' · '}{ __('Snapshot:', 'wp-pfagent') }{' '}<code>{model.equivalentSnapshot}</code>
                                      </>
                                    ) : null}
                                  </p>
                                ) : null}
                              </li>
                            );
                          })}
                        </ul>
                        <div className="pfa-manage__actions">
                          <button
                            type="button"
                            className="pfa-wizard__primary"
                            onClick={() => void manageSaveModels()}
                            disabled={manageBusy}
                          >
                            { __('Save model config', 'wp-pfagent') }
                          </button>
                          {manageModelsSavedAt ? (
                            <span className="pfa-wizard__hint">{ __('Saved.', 'wp-pfagent') }</span>
                          ) : null}
                        </div>
                      </>
                    ) : (
                      <p className="pfa-wizard__empty">{ __('No models returned.', 'wp-pfagent') }</p>
                    )}
                  </>
                ) : (
                  <p className="pfa-wizard__hint">
                    { __('No model list loaded yet. Click Load to fetch from the API or Refresh to bypass cache.', 'wp-pfagent') }
                  </p>
                )}

                {managePreset && manualModelsAllowed(managePreset) ? (
                  <div className="pfa-manage__manual">
                    <label className="pfa-wizard__field">
                      <span>{ __('Manual model IDs', 'wp-pfagent') }</span>
                      <textarea
                        value={manageManualInput}
                        onChange={(event) => setManageManualInput(event.target.value)}
                        placeholder={ __('one model id per line', 'wp-pfagent') }
                        rows={3}
                        disabled={manageBusy}
                      />
                    </label>
                    <div className="pfa-manage__actions">
                      <button
                        type="button"
                        className="pfa-wizard__primary"
                        onClick={() => void manageSaveManual()}
                        disabled={manageBusy || manageManualInput.trim() === ''}
                      >
                        { __('Save manual list', 'wp-pfagent') }
                      </button>
                    </div>
                  </div>
                ) : null}
              </section>
            </div>

            <footer className="pfa-modal__actions">
              <button
                type="button"
                className="pfa-wizard__cancel"
                onClick={closeManage}
                disabled={manageBusy}
              >
                { __('Close', 'wp-pfagent') }
              </button>
            </footer>
          </div>
        </div>
      ) : null}

      {permsOpen ? (
        <div className="pfa-modal-backdrop" role="presentation" onClick={closePermissions}>
          <div
            className="pfa-modal pfa-modal--wide"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pfa-perms-title"
            onClick={(e) => e.stopPropagation()}
          >
            <header className="pfa-modal__header">
              <h2 id="pfa-perms-title">{ __('Permission rules', 'wp-pfagent') }</h2>
              <button type="button" className="pfa-modal__close" onClick={closePermissions} disabled={permsBusy} aria-label={ __('Close', 'wp-pfagent') }>
                <X size={16} aria-hidden="true" />
              </button>
            </header>
            <div className="pfa-modal__body">
              <p className="pfa-wizard__hint">
                { __('For each thing the agent can do, choose whether it runs straight away, asks you first, or is not allowed at all. "Ask" is the safe default.', 'wp-pfagent') }
              </p>
              {permsError ? (
                <div className="pfa-banner pfa-banner--error" role="alert">
                  <strong>{ __('Error:', 'wp-pfagent') }</strong> {permsError}
                </div>
              ) : null}

              <div className="pfa-perms-default">
                <div className="pfa-perms-row__text">
                  <strong>{ __('Default for any other action', 'wp-pfagent') }</strong>
                  <span className="pfa-wizard__hint">
                    { __('Used when a future capability is added that you have not set up yet.', 'wp-pfagent') }
                  </span>
                </div>
                <select
                  className="pfa-perms-row__select"
                  value={permsDefault}
                  disabled={permsBusy}
                  onChange={(e) => setPermsDefault(e.target.value as PermissionVerdict)}
                >
                  <option value="allow">{ __('Allow', 'wp-pfagent') }</option>
                  <option value="ask">{ __('Ask', 'wp-pfagent') }</option>
                  <option value="deny">{ __('Deny', 'wp-pfagent') }</option>
                </select>
              </div>

              <ul className="pfa-perms-list">
                {permsTools.map((tool) => {
                  const display = toolDisplay(tool.name);
                  const isAdvanced = permsAdvancedTools.has(tool.name);
                  const verdict = permsVerdictByTool[tool.name] ?? 'ask';
                  return (
                    <li key={tool.name} className="pfa-perms-row">
                      <div className="pfa-perms-row__text">
                        <strong>{display.label}</strong>
                        <span className="pfa-wizard__hint">{display.summary}</span>
                      </div>
                      {isAdvanced ? (
                        <span className="pfa-perms-row__chip" title={ __('A custom rule is in place. Use the advanced editor to change it.', 'wp-pfagent') }>
                          { __('Custom rule', 'wp-pfagent') }
                        </span>
                      ) : (
                        <select
                          className="pfa-perms-row__select"
                          value={verdict}
                          disabled={permsBusy}
                          onChange={(e) => setVerdictForTool(tool.name, e.target.value as PermissionVerdict)}
                        >
                          <option value="allow">{ __('Allow', 'wp-pfagent') }</option>
                          <option value="ask">{ __('Ask', 'wp-pfagent') }</option>
                          <option value="deny">{ __('Deny', 'wp-pfagent') }</option>
                        </select>
                      )}
                    </li>
                  );
                })}
              </ul>

              <div className="pfa-perms-advanced">
                <button
                  type="button"
                  className="pfa-perms-advanced__toggle"
                  onClick={() => setPermsShowAdvanced((v) => !v)}
                  disabled={permsBusy}
                >
                  {permsShowAdvanced
                    ? __('Hide advanced editor', 'wp-pfagent')
                    : __('Show advanced editor', 'wp-pfagent')}
                </button>
                {permsShowAdvanced ? (
                  <>
                    <p className="pfa-wizard__hint">
                      { __('Edit the raw rules JSON. Use this for argument-pattern rules that the simple list above cannot express.', 'wp-pfagent') }
                    </p>
                    <label className="pfa-wizard__field">
                      <span>{ __('Rules JSON', 'wp-pfagent') }</span>
                      <textarea
                        value={permsAdvancedText}
                        onChange={(event) => setPermsAdvancedText(event.target.value)}
                        rows={14}
                        spellCheck={false}
                        disabled={permsBusy}
                      />
                    </label>
                    <button
                      type="button"
                      className="pfa-wizard__primary"
                      onClick={() => void saveAdvancedClick()}
                      disabled={permsBusy}
                    >
                      { __('Save advanced JSON', 'wp-pfagent') }
                    </button>
                  </>
                ) : null}
              </div>

              {permsSavedAt ? (
                <p className="pfa-wizard__hint">{ sprintf(__('Last updated: %s', 'wp-pfagent'), permsSavedAt) }</p>
              ) : null}
            </div>
            <footer className="pfa-modal__actions">
              <button type="button" className="pfa-wizard__primary" onClick={() => void savePermissionsClick()} disabled={permsBusy}>
                { __('Save', 'wp-pfagent') }
              </button>
              <button type="button" className="pfa-wizard__cancel" onClick={closePermissions} disabled={permsBusy}>
                { __('Close', 'wp-pfagent') }
              </button>
            </footer>
          </div>
        </div>
      ) : null}

    </section>
  );
}

function messageOf(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

function credentialStatusLabel(status: string): string {
  switch (status) {
    case 'validated':
      return __('Verified', 'wp-pfagent');
    case 'configured_unvalidated':
      return __('Not verified', 'wp-pfagent');
    case 'validation_failed':
      return __('Validation failed', 'wp-pfagent');
    case 'not_configured':
      return __('Not configured', 'wp-pfagent');
    default:
      return status;
  }
}

function placeholdersFor(preset: ProviderPreset): string[] {
  const out = new Set<string>();
  for (const match of preset.baseUrl.matchAll(/{{\s*([a-zA-Z0-9_]+)\s*}}/g)) {
    const key = match[1];
    if (!['api_key', 'model', 'anthropic_version'].includes(key)) {
      out.add(key);
    }
  }
  return Array.from(out);
}

function labelForSetting(key: string): string {
  return key
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

function effectiveSettings(
  status: ProviderCredentialStatus | null,
  draft: Record<string, string>
): Record<string, string> {
  return {
    ...(status?.settings ?? {}),
    ...Object.fromEntries(Object.entries(draft).filter(([, value]) => value.trim() !== '')),
  };
}

function manualModelsAllowed(preset: ProviderPreset): boolean {
  return ['api_or_manual', 'manual', 'deployment_config', 'provider_specific'].includes(preset.modelDiscovery);
}

function parseOptionalInt(value: string | undefined): number | undefined {
  if (value === undefined || value.trim() === '') return undefined;
  const n = Number.parseInt(value, 10);
  if (!Number.isFinite(n) || n <= 0) return undefined;
  return n;
}

function parseOptionalFloat(value: string | undefined): number | undefined {
  if (value === undefined || value.trim() === '') return undefined;
  const n = Number.parseFloat(value);
  if (!Number.isFinite(n) || n < 0) return undefined;
  return n;
}

function buildPricing(
  current: ProviderModelPricing | undefined,
  draft: { input?: string; output?: string; cacheRead?: string; cacheWrite?: string }
): ProviderModelPricing | undefined {
  const input = parseOptionalFloat(draft.input) ?? current?.input;
  const output = parseOptionalFloat(draft.output) ?? current?.output;
  const cacheRead = parseOptionalFloat(draft.cacheRead) ?? current?.cacheRead;
  const cacheWrite = parseOptionalFloat(draft.cacheWrite) ?? current?.cacheWrite;
  const tiers = current?.tiers;

  if (
    input === undefined &&
    output === undefined &&
    cacheRead === undefined &&
    cacheWrite === undefined &&
    (tiers === undefined || tiers.length === 0)
  ) {
    return undefined;
  }

  const result: ProviderModelPricing = {};
  if (input !== undefined) result.input = input;
  if (output !== undefined) result.output = output;
  if (cacheRead !== undefined) result.cacheRead = cacheRead;
  if (cacheWrite !== undefined) result.cacheWrite = cacheWrite;
  if (tiers && tiers.length > 0) result.tiers = tiers;
  return result;
}

/**
 * Build a per-row draft from a previously-saved model record + the fresh
 * discovery for the same id. Discovery wins for fields the API exposes;
 * the saved record's user-entered fields populate the editable inputs.
 */
function draftFromSavedModel(saved: ProviderModel, discovered: ProviderModel): ModelEditDraft {
  const apiCtx = discovered.contextLength;
  const apiOut = discovered.maxOutputTokens;
  const draft: ModelEditDraft = { pricing: {} };
  if (saved.contextLength !== undefined && saved.contextLength !== apiCtx) {
    draft.contextLength = String(saved.contextLength);
  }
  if (saved.maxOutputTokens !== undefined && saved.maxOutputTokens !== apiOut) {
    draft.maxOutputTokens = String(saved.maxOutputTokens);
  }
  if (saved.defaultReasoningEffort) {
    draft.defaultReasoningEffort = saved.defaultReasoningEffort;
  }
  if (saved.pricing) {
    if (saved.pricing.input !== undefined) draft.pricing.input = String(saved.pricing.input);
    if (saved.pricing.output !== undefined) draft.pricing.output = String(saved.pricing.output);
    if (saved.pricing.cacheRead !== undefined) draft.pricing.cacheRead = String(saved.pricing.cacheRead);
    if (saved.pricing.cacheWrite !== undefined) draft.pricing.cacheWrite = String(saved.pricing.cacheWrite);
  }
  return draft;
}

