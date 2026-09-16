import type {
  AppConfig,
  ExecutionLog,
  Workflow,
  WorkflowGraph,
  AgentContract,
  AgentFixSuggestionsRequest,
  AgentFixSuggestionsResult,
  AgentInternalDocs,
  AgentOpenApiDocument,
  AgentToolCatalog,
  ProviderCredentialCatalog,
  ProviderCredentialStatus,
  ProviderGenerationResult,
  ProviderHealthResult,
  ProviderModel,
  ProviderModelCatalog,
  ProviderPresetCatalog,
  AgentMetricsResponse,
  AgentRuntimeProgress,
  AgentRuntimeTurnResult,
  BetaReadinessReport,
  ChatSession,
  ChatSessionMessage,
  ChatSessionsPage,
  ChatSessionsPurgeResult,
  WorkflowRunResult,
  WorkflowTemplateCatalog,
  WorkflowValidationResult
} from './types';

/**
 * Build a REST URL that works under BOTH pretty and plain permalinks.
 *
 * `restUrl` from WordPress is either the pretty form ("…/wp-json/wp-pfagent/v1/")
 * or, on a freshly-installed / "plain" permalink site, the query form
 * ("http://host/?rest_route=/wp-pfagent/v1/"). `new URL(endpoint, restUrl)`
 * resolves the endpoint against the base PATH and DROPS the "?rest_route=…"
 * query, so the plain form would hit the WP front page (HTML) instead of the
 * REST route — crashing the SPA on any plain-permalink site (the same bug that
 * hit PFM/portal/PFW). Concatenation respects both. The endpoint may carry its
 * own query string (e.g. "agent-runtime/progress?since=5"); when the base is
 * already the "?rest_route=" form that query must be joined with "&", never a
 * second "?".
 */
export function buildRestUrl(base: string, endpoint: string): string {
  const clean = endpoint.replace(/^\/+/, '');
  const qIdx = clean.indexOf('?');
  const path = qIdx === -1 ? clean : clean.slice(0, qIdx);
  const query = qIdx === -1 ? '' : clean.slice(qIdx + 1);
  let url = base + path;
  if (query) {
    url += (url.includes('?') ? '&' : '?') + query;
  }
  return url;
}

export interface WorkflowApiRequestOptions {
  signal?: AbortSignal;
  retries?: number;
  retryDelayMs?: number;
}

export interface WorkflowLogQuery {
  limit?: number;
  status?: string;
}

export interface WorkflowApiTimelineItem {
  id: string;
  at: string;
  event: 'request_started' | 'request_retry' | 'request_succeeded' | 'request_failed';
  method: string;
  endpoint: string;
  status?: number;
  attempt: number;
  durationMs?: number;
  errorCode?: string;
  message?: string;
}

interface WorkflowRequestInit<TBody = unknown> extends WorkflowApiRequestOptions {
  method?: string;
  body?: TBody;
}

const workflowApiTimeline: WorkflowApiTimelineItem[] = [];

export class WorkflowApiError extends Error {
  readonly name = 'WorkflowApiError';
  readonly status: number;
  readonly code: string;
  readonly endpoint: string;
  readonly method: string;
  readonly payload: unknown;

  constructor(input: {
    message: string;
    status: number;
    code: string;
    endpoint: string;
    method: string;
    payload?: unknown;
  }) {
    super(input.message);
    this.status = input.status;
    this.code = input.code;
    this.endpoint = input.endpoint;
    this.method = input.method;
    this.payload = input.payload;
  }

  get isPermissionError(): boolean {
    return this.status === 401 || this.status === 403;
  }
}

export class WorkflowApiClient {
  constructor(private readonly config: AppConfig) {}

  listWorkflows(options: WorkflowApiRequestOptions = {}): Promise<Workflow[]> {
    return this.request<Workflow[]>('workflows', { ...options, method: 'GET' });
  }

  getWorkflow(id: Workflow['id'], options: WorkflowApiRequestOptions = {}): Promise<Workflow> {
    return this.request<Workflow>(`workflows/${id}`, { ...options, method: 'GET' });
  }

  listTemplates(options: WorkflowApiRequestOptions = {}): Promise<WorkflowTemplateCatalog> {
    return this.request<WorkflowTemplateCatalog>('templates', { ...options, method: 'GET' });
  }

  createWorkflow(data: { name: string; status?: Workflow['status']; graph: WorkflowGraph }, options: WorkflowApiRequestOptions = {}) {
    return this.request<Workflow>('workflows', {
      ...options,
      method: 'POST',
      body: {
        name: data.name,
        status: data.status ?? 'draft',
        graph: data.graph
      }
    });
  }

  createWorkflowFromTemplate(name: string, graph: WorkflowGraph, options: WorkflowApiRequestOptions = {}): Promise<Workflow> {
    return this.createWorkflow({ name, status: 'draft', graph }, options);
  }

  validateWorkflow(
    id: Workflow['id'],
    graph: WorkflowGraph,
    status: Workflow['status'] = 'draft',
    options: WorkflowApiRequestOptions = {}
  ): Promise<WorkflowValidationResult> {
    return this.request<WorkflowValidationResult>(`workflows/${id}/validate`, {
      ...options,
      method: 'POST',
      body: { graph, status }
    });
  }

  testRunWorkflow(id: Workflow['id'], input: Record<string, unknown> = {}, options: WorkflowApiRequestOptions = {}) {
    return this.request<WorkflowRunResult>(`workflows/${id}/test-run`, {
      ...options,
      method: 'POST',
      body: input
    });
  }

  runWorkflow(id: Workflow['id'], input: Record<string, unknown> = {}, options: WorkflowApiRequestOptions = {}) {
    return this.request<WorkflowRunResult>(`workflows/${id}/run`, {
      ...options,
      method: 'POST',
      body: input
    });
  }

  workflowLogs(id: Workflow['id'], query: WorkflowLogQuery = {}, options: WorkflowApiRequestOptions = {}): Promise<ExecutionLog[]> {
    const params = new URLSearchParams();
    if (query.limit !== undefined) {
      params.set('limit', String(query.limit));
    }
    if (query.status) {
      params.set('status', query.status);
    }

    const suffix = params.toString() ? `?${params.toString()}` : '';

    return this.request<ExecutionLog[]>(`workflows/${id}/logs${suffix}`, { ...options, method: 'GET' });
  }

  private async request<T>(endpoint: string, init: WorkflowRequestInit = {}): Promise<T> {
    const method = init.method ?? 'GET';
    const retries = Math.max(0, init.retries ?? 0);
    const retryDelayMs = Math.max(0, init.retryDelayMs ?? 180);
    let attempt = 0;

    while (true) {
      attempt += 1;
      const started = performance.now();
      const requestId = timelineId();

      pushTimeline({
        id: requestId,
        at: new Date().toISOString(),
        event: 'request_started',
        method,
        endpoint,
        attempt
      });

      try {
        const result = await this.fetchJson<T>(endpoint, method, init);
        pushTimeline({
          id: requestId,
          at: new Date().toISOString(),
          event: 'request_succeeded',
          method,
          endpoint,
          attempt,
          durationMs: Math.round(performance.now() - started)
        });

        return result;
      } catch (error) {
        const normalized = normalizeWorkflowApiError(error, endpoint, method);
        pushTimeline({
          id: requestId,
          at: new Date().toISOString(),
          event: 'request_failed',
          method,
          endpoint,
          attempt,
          status: normalized.status,
          durationMs: Math.round(performance.now() - started),
          errorCode: normalized.code,
          message: normalized.message
        });

        if (attempt <= retries && shouldRetry(normalized)) {
          pushTimeline({
            id: timelineId(),
            at: new Date().toISOString(),
            event: 'request_retry',
            method,
            endpoint,
            attempt: attempt + 1,
            status: normalized.status,
            errorCode: normalized.code,
            message: normalized.message
          });
          await delay(retryDelayMs);
          continue;
        }

        throw normalized;
      }
    }
  }

  private async fetchJson<T>(endpoint: string, method: string, init: WorkflowRequestInit): Promise<T> {
    if (init.signal?.aborted) {
      throw new WorkflowApiError({
        message: 'Workflow API request was aborted before it started.',
        status: 0,
        code: 'aborted',
        endpoint,
        method
      });
    }

    const url = buildRestUrl(this.config.workflowRestUrl, endpoint);
    const response = await fetch(url, {
      method,
      signal: init.signal,
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': this.config.nonce
      },
      credentials: 'same-origin'
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
      throw errorFromResponse(response, endpoint, method, payload);
    }

    if (payload === null || payload === undefined) {
      throw new WorkflowApiError({
        message: `Workflow API ${method} ${endpoint} returned an empty response.`,
        status: response.status,
        code: 'empty_response',
        endpoint,
        method,
        payload
      });
    }

    if (isEmptyPlainObject(payload)) {
      throw new WorkflowApiError({
        message: `Workflow API ${method} ${endpoint} returned an empty object; refusing to treat it as success.`,
        status: response.status,
        code: 'empty_object_response',
        endpoint,
        method,
        payload
      });
    }

    return payload as T;
  }
}

export class AgentApiClient {
  constructor(private readonly config: AppConfig) {}

  contract(options: WorkflowApiRequestOptions = {}): Promise<AgentContract> {
    return this.request<AgentContract>('contract', { ...options, method: 'GET' });
  }

  openApiContract(options: WorkflowApiRequestOptions = {}): Promise<AgentOpenApiDocument> {
    return this.request<AgentOpenApiDocument>('contract/openapi', { ...options, method: 'GET' });
  }

  listAgentTools(options: WorkflowApiRequestOptions = {}): Promise<AgentToolCatalog> {
    return this.request<AgentToolCatalog>('agent-runtime/tools', { ...options, method: 'GET' });
  }

  internalDocs(options: WorkflowApiRequestOptions = {}): Promise<AgentInternalDocs> {
    return this.request<AgentInternalDocs>('agent-runtime/internal-docs', { ...options, method: 'GET' });
  }

  fixSuggestions(input: AgentFixSuggestionsRequest, options: WorkflowApiRequestOptions = {}): Promise<AgentFixSuggestionsResult> {
    return this.request<AgentFixSuggestionsResult>('agent-runtime/fix-suggestions', {
      ...options,
      method: 'POST',
      body: input
    });
  }

  listProviderPresets(options: WorkflowApiRequestOptions = {}): Promise<ProviderPresetCatalog> {
    return this.request<ProviderPresetCatalog>('provider-presets', { ...options, method: 'GET' });
  }

  listProviderCredentials(options: WorkflowApiRequestOptions = {}): Promise<ProviderCredentialCatalog> {
    return this.request<ProviderCredentialCatalog>('provider-credentials', { ...options, method: 'GET' });
  }

  saveProviderCredential(
    providerId: string,
    data: { apiKey: string; settings?: Record<string, string> },
    options: WorkflowApiRequestOptions = {}
  ): Promise<ProviderCredentialStatus> {
    return this.request<ProviderCredentialStatus>(`provider-credentials/${encodeURIComponent(providerId)}`, {
      ...options,
      method: 'POST',
      body: {
        apiKey: data.apiKey,
        settings: data.settings ?? {}
      }
    });
  }

  rotateProviderCredential(
    providerId: string,
    data: { apiKey: string; settings?: Record<string, string> },
    options: WorkflowApiRequestOptions = {}
  ): Promise<ProviderCredentialStatus> {
    return this.request<ProviderCredentialStatus>(`provider-credentials/${encodeURIComponent(providerId)}/rotate`, {
      ...options,
      method: 'POST',
      body: {
        apiKey: data.apiKey,
        settings: data.settings ?? {}
      }
    });
  }

  deleteProviderCredential(providerId: string, options: WorkflowApiRequestOptions = {}): Promise<ProviderCredentialStatus> {
    return this.request<ProviderCredentialStatus>(`provider-credentials/${encodeURIComponent(providerId)}`, {
      ...options,
      method: 'DELETE'
    });
  }

  testProviderCredential(providerId: string, options: WorkflowApiRequestOptions = {}): Promise<ProviderCredentialStatus> {
    return this.request<ProviderCredentialStatus>(`provider-credentials/${encodeURIComponent(providerId)}/test`, {
      ...options,
      method: 'POST'
    });
  }

  generateProviderSmoke(providerId: string, options: WorkflowApiRequestOptions = {}): Promise<ProviderGenerationResult> {
    return this.request<ProviderGenerationResult>(`provider-runtime/${encodeURIComponent(providerId)}/smoke`, {
      ...options,
      method: 'POST'
    });
  }

  listProviderModels(providerId: string, input: { force?: boolean } = {}, options: WorkflowApiRequestOptions = {}): Promise<ProviderModelCatalog> {
    const query = input.force ? '?force=1' : '';
    return this.request<ProviderModelCatalog>(`provider-models/${encodeURIComponent(providerId)}${query}`, {
      ...options,
      method: 'GET'
    });
  }

  saveManualProviderModels(providerId: string, models: Array<string | { id: string; label?: string }>, options: WorkflowApiRequestOptions = {}): Promise<ProviderModelCatalog> {
    return this.request<ProviderModelCatalog>(`provider-models/${encodeURIComponent(providerId)}/manual`, {
      ...options,
      method: 'POST',
      body: { models }
    });
  }

  saveProviderModels(providerId: string, models: ProviderModel[], options: WorkflowApiRequestOptions = {}): Promise<ProviderCredentialStatus> {
    return this.request<ProviderCredentialStatus>(`provider-models/${encodeURIComponent(providerId)}/save`, {
      ...options,
      method: 'POST',
      body: { models }
    });
  }

  checkProviderHealth(providerId: string, options: WorkflowApiRequestOptions = {}): Promise<ProviderHealthResult> {
    return this.request<ProviderHealthResult>(`provider-health/${encodeURIComponent(providerId)}`, {
      ...options,
      method: 'POST'
    });
  }

  betaReadiness(options: WorkflowApiRequestOptions = {}): Promise<BetaReadinessReport> {
    return this.request<BetaReadinessReport>('agent-runtime/beta-readiness', { ...options, method: 'GET' });
  }

  agentMetrics(windowHours: number | null = null, options: WorkflowApiRequestOptions = {}): Promise<AgentMetricsResponse> {
    const suffix = windowHours !== null ? `?windowHours=${encodeURIComponent(String(windowHours))}` : '';
    return this.request<AgentMetricsResponse>(`agent-runtime/metrics${suffix}`, { ...options, method: 'GET' });
  }

  agentTurn(input: { providerId: string; model?: string; message?: string; conversationId?: string; label?: string }, options: WorkflowApiRequestOptions = {}): Promise<AgentRuntimeTurnResult> {
    return this.request<AgentRuntimeTurnResult>('agent-runtime/turn-v2', {
      ...options,
      method: 'POST',
      body: input
    });
  }

  agentResume(input: { providerId: string; model: string; conversationId: string; confirmationToken: string; approved: boolean }, options: WorkflowApiRequestOptions = {}): Promise<AgentRuntimeTurnResult> {
    return this.request<AgentRuntimeTurnResult>('agent-runtime/resume-v2', {
      ...options,
      method: 'POST',
      body: input
    });
  }

  // H5: continue a turn that paused on its time budget (result.continuation).
  // No confirmation token — just the conversation + the same provider/model.
  agentContinue(input: { providerId: string; model: string; conversationId: string }, options: WorkflowApiRequestOptions = {}): Promise<AgentRuntimeTurnResult> {
    return this.request<AgentRuntimeTurnResult>('agent-runtime/continue-v2', {
      ...options,
      method: 'POST',
      body: input
    });
  }

  agentProgress(
    input: { conversationId: string; sinceToolCallSeq?: number; sinceTraceSeq?: number; sinceMessageOrdinal?: number },
    options: WorkflowApiRequestOptions = {}
  ): Promise<AgentRuntimeProgress> {
    const q = new URLSearchParams();
    q.set('conversationId', input.conversationId);
    if (typeof input.sinceToolCallSeq === 'number') q.set('sinceToolCallSeq', String(input.sinceToolCallSeq));
    if (typeof input.sinceTraceSeq === 'number') q.set('sinceTraceSeq', String(input.sinceTraceSeq));
    if (typeof input.sinceMessageOrdinal === 'number') q.set('sinceMessageOrdinal', String(input.sinceMessageOrdinal));
    return this.request<AgentRuntimeProgress>(`agent-runtime/progress?${q.toString()}`, {
      ...options,
      method: 'GET'
    });
  }

  getPermissionRules(options: WorkflowApiRequestOptions = {}): Promise<{ rules: Record<string, unknown>; updatedAt: string }> {
    return this.request<{ rules: Record<string, unknown>; updatedAt: string }>('agent-runtime/permission-rules', {
      ...options,
      method: 'GET'
    });
  }

  savePermissionRules(rules: Record<string, unknown>, options: WorkflowApiRequestOptions = {}): Promise<{ rules: Record<string, unknown>; updatedAt: string }> {
    return this.request<{ rules: Record<string, unknown>; updatedAt: string }>('agent-runtime/permission-rules', {
      ...options,
      method: 'PUT',
      body: { rules }
    });
  }

  getActiveLlm(options: WorkflowApiRequestOptions = {}): Promise<{ providerId: string; model: string; sessionId: string | null; updatedAt: string }> {
    return this.request<{ providerId: string; model: string; sessionId: string | null; updatedAt: string }>('active-llm', {
      ...options,
      method: 'GET'
    });
  }

  setActiveLlm(
    input: { providerId: string; model: string; sessionId: string | null },
    options: WorkflowApiRequestOptions = {}
  ): Promise<{ providerId: string; model: string; sessionId: string | null; updatedAt: string }> {
    return this.request<{ providerId: string; model: string; sessionId: string | null; updatedAt: string }>('active-llm', {
      ...options,
      method: 'PUT',
      body: {
        providerId: input.providerId,
        model: input.model,
        sessionId: input.sessionId
      }
    });
  }

  listChatSessions(
    input: { page?: number; perPage?: number } = {},
    options: WorkflowApiRequestOptions = {}
  ): Promise<ChatSessionsPage> {
    const params = new URLSearchParams();
    if (typeof input.page === 'number' && input.page > 0) {
      params.set('page', String(input.page));
    }
    if (typeof input.perPage === 'number' && input.perPage > 0) {
      params.set('perPage', String(input.perPage));
    }
    const suffix = params.toString() ? `?${params.toString()}` : '';
    return this.request<ChatSessionsPage>(`chat-sessions${suffix}`, { ...options, method: 'GET' });
  }

  createChatSession(input: { label?: string; workflowId?: Workflow['id'] }, options: WorkflowApiRequestOptions = {}): Promise<ChatSession> {
    return this.request<ChatSession>('chat-sessions', { ...options, method: 'POST', body: input });
  }

  purgeChatSessions(
    input: { olderThanDays?: number } = {},
    options: WorkflowApiRequestOptions = {}
  ): Promise<ChatSessionsPurgeResult> {
    return this.request<ChatSessionsPurgeResult>('chat-sessions/purge', {
      ...options,
      method: 'POST',
      body: input,
    });
  }

  getChatSession(id: string, options: WorkflowApiRequestOptions = {}): Promise<ChatSession> {
    return this.request<ChatSession>(`chat-sessions/${id}`, { ...options, method: 'GET' });
  }

  patchChatSession(id: string, input: { label?: string; workflowId?: Workflow['id'] | null }, options: WorkflowApiRequestOptions = {}): Promise<ChatSession> {
    return this.request<ChatSession>(`chat-sessions/${id}`, { ...options, method: 'PATCH', body: input });
  }

  deleteChatSession(id: string, options: WorkflowApiRequestOptions = {}): Promise<{ deleted: boolean; id: string }> {
    return this.request<{ deleted: boolean; id: string }>(`chat-sessions/${id}`, { ...options, method: 'DELETE' });
  }

  appendChatMessages(id: string, messages: ChatSessionMessage[], options: WorkflowApiRequestOptions = {}): Promise<ChatSession> {
    return this.request<ChatSession>(`chat-sessions/${id}/messages`, { ...options, method: 'POST', body: { messages } });
  }

  private async request<T>(endpoint: string, init: WorkflowRequestInit = {}): Promise<T> {
    const method = init.method ?? 'GET';
    const url = buildRestUrl(this.config.restUrl, endpoint);
    const response = await fetch(url, {
      method,
      signal: init.signal,
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': this.config.nonce
      },
      credentials: 'same-origin'
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
      throw errorFromResponse(response, endpoint, method, payload);
    }

    if (payload === null || payload === undefined || isEmptyPlainObject(payload)) {
      throw new WorkflowApiError({
        message: `WP PFAgent API ${method} ${endpoint} returned an invalid empty response.`,
        status: response.status,
        code: 'empty_response',
        endpoint,
        method,
        payload
      });
    }

    return payload as T;
  }
}

export function getConfig(): AppConfig {
  const config = globalThis.window?.ProjectFlashAgent;
  if (!config) {
    throw new Error('WP PFAgent config is missing.');
  }

  return config;
}

export function workflowApi(): WorkflowApiClient {
  return new WorkflowApiClient(getConfig());
}

export function agentApi(): AgentApiClient {
  return new AgentApiClient(getConfig());
}

export function getWorkflowApiTimeline(): WorkflowApiTimelineItem[] {
  return [...workflowApiTimeline];
}

export function clearWorkflowApiTimeline(): void {
  workflowApiTimeline.splice(0, workflowApiTimeline.length);
}

export function listWorkflows(options?: WorkflowApiRequestOptions): Promise<Workflow[]> {
  return workflowApi().listWorkflows(options);
}

export function getWorkflow(id: Workflow['id'], options?: WorkflowApiRequestOptions): Promise<Workflow> {
  return workflowApi().getWorkflow(id, options);
}

export function listTemplates(options?: WorkflowApiRequestOptions): Promise<WorkflowTemplateCatalog> {
  return workflowApi().listTemplates(options);
}

export function createWorkflowFromTemplate(
  name: string,
  graph: WorkflowGraph,
  options?: WorkflowApiRequestOptions
): Promise<Workflow> {
  return workflowApi().createWorkflowFromTemplate(name, graph, options);
}

export function validateWorkflow(
  id: Workflow['id'],
  graph: WorkflowGraph,
  status: Workflow['status'] = 'draft',
  options?: WorkflowApiRequestOptions
): Promise<WorkflowValidationResult> {
  return workflowApi().validateWorkflow(id, graph, status, options);
}

export function testRunWorkflow(
  id: Workflow['id'],
  input: Record<string, unknown> = {},
  options?: WorkflowApiRequestOptions
): Promise<WorkflowRunResult> {
  return workflowApi().testRunWorkflow(id, input, options);
}

export function runWorkflow(
  id: Workflow['id'],
  input: Record<string, unknown> = {},
  options?: WorkflowApiRequestOptions
): Promise<WorkflowRunResult> {
  return workflowApi().runWorkflow(id, input, options);
}

export function workflowLogs(id: Workflow['id'], query: WorkflowLogQuery = {}, options?: WorkflowApiRequestOptions): Promise<ExecutionLog[]> {
  return workflowApi().workflowLogs(id, query, options);
}

export function listProviderPresets(options?: WorkflowApiRequestOptions): Promise<ProviderPresetCatalog> {
  return agentApi().listProviderPresets(options);
}

export function getAgentContract(options?: WorkflowApiRequestOptions): Promise<AgentContract> {
  return agentApi().contract(options);
}

export function getAgentOpenApiContract(options?: WorkflowApiRequestOptions): Promise<AgentOpenApiDocument> {
  return agentApi().openApiContract(options);
}

export function listAgentTools(options?: WorkflowApiRequestOptions): Promise<AgentToolCatalog> {
  return agentApi().listAgentTools(options);
}

export function getAgentInternalDocs(options?: WorkflowApiRequestOptions): Promise<AgentInternalDocs> {
  return agentApi().internalDocs(options);
}

export function suggestAgentFixes(input: AgentFixSuggestionsRequest, options?: WorkflowApiRequestOptions): Promise<AgentFixSuggestionsResult> {
  return agentApi().fixSuggestions(input, options);
}

export function listProviderCredentials(options?: WorkflowApiRequestOptions): Promise<ProviderCredentialCatalog> {
  return agentApi().listProviderCredentials(options);
}

export function saveProviderCredential(
  providerId: string,
  data: { apiKey: string; settings?: Record<string, string> },
  options?: WorkflowApiRequestOptions
): Promise<ProviderCredentialStatus> {
  return agentApi().saveProviderCredential(providerId, data, options);
}

export function rotateProviderCredential(
  providerId: string,
  data: { apiKey: string; settings?: Record<string, string> },
  options?: WorkflowApiRequestOptions
): Promise<ProviderCredentialStatus> {
  return agentApi().rotateProviderCredential(providerId, data, options);
}

export function deleteProviderCredential(providerId: string, options?: WorkflowApiRequestOptions): Promise<ProviderCredentialStatus> {
  return agentApi().deleteProviderCredential(providerId, options);
}

export function testProviderCredential(providerId: string, options?: WorkflowApiRequestOptions): Promise<ProviderCredentialStatus> {
  return agentApi().testProviderCredential(providerId, options);
}

export function generateProviderSmoke(providerId: string, options?: WorkflowApiRequestOptions): Promise<ProviderGenerationResult> {
  return agentApi().generateProviderSmoke(providerId, options);
}

export function listProviderModels(providerId: string, input: { force?: boolean } = {}, options?: WorkflowApiRequestOptions): Promise<ProviderModelCatalog> {
  return agentApi().listProviderModels(providerId, input, options);
}

export function saveManualProviderModels(
  providerId: string,
  models: Array<string | { id: string; label?: string }>,
  options?: WorkflowApiRequestOptions
): Promise<ProviderModelCatalog> {
  return agentApi().saveManualProviderModels(providerId, models, options);
}

export function saveProviderModels(
  providerId: string,
  models: ProviderModel[],
  options?: WorkflowApiRequestOptions
): Promise<ProviderCredentialStatus> {
  return agentApi().saveProviderModels(providerId, models, options);
}

export function checkProviderHealth(providerId: string, options?: WorkflowApiRequestOptions): Promise<ProviderHealthResult> {
  return agentApi().checkProviderHealth(providerId, options);
}


export function agentTurn(
  input: { providerId: string; model?: string; message?: string; conversationId?: string; label?: string },
  options?: WorkflowApiRequestOptions
): Promise<AgentRuntimeTurnResult> {
  return agentApi().agentTurn(input, options);
}

export function agentResume(
  input: { providerId: string; model: string; conversationId: string; confirmationToken: string; approved: boolean },
  options?: WorkflowApiRequestOptions
): Promise<AgentRuntimeTurnResult> {
  return agentApi().agentResume(input, options);
}

export function agentContinue(
  input: { providerId: string; model: string; conversationId: string },
  options?: WorkflowApiRequestOptions
): Promise<AgentRuntimeTurnResult> {
  return agentApi().agentContinue(input, options);
}

export function agentProgress(
  input: { conversationId: string; sinceToolCallSeq?: number; sinceTraceSeq?: number; sinceMessageOrdinal?: number },
  options?: WorkflowApiRequestOptions
): Promise<AgentRuntimeProgress> {
  return agentApi().agentProgress(input, options);
}

export function getPermissionRules(
  options?: WorkflowApiRequestOptions
): Promise<{ rules: Record<string, unknown>; updatedAt: string }> {
  return agentApi().getPermissionRules(options);
}

export function savePermissionRules(
  rules: Record<string, unknown>,
  options?: WorkflowApiRequestOptions
): Promise<{ rules: Record<string, unknown>; updatedAt: string }> {
  return agentApi().savePermissionRules(rules, options);
}

export function getActiveLlm(
  options?: WorkflowApiRequestOptions
): Promise<{ providerId: string; model: string; sessionId: string | null; updatedAt: string }> {
  return agentApi().getActiveLlm(options);
}

export function setActiveLlm(
  input: { providerId: string; model: string; sessionId: string | null },
  options?: WorkflowApiRequestOptions
): Promise<{ providerId: string; model: string; sessionId: string | null; updatedAt: string }> {
  return agentApi().setActiveLlm(input, options);
}

export function getBetaReadiness(options?: WorkflowApiRequestOptions): Promise<BetaReadinessReport> {
  return agentApi().betaReadiness(options);
}

export function getAgentMetrics(windowHours: number | null = null, options?: WorkflowApiRequestOptions): Promise<AgentMetricsResponse> {
  return agentApi().agentMetrics(windowHours, options);
}

export function listChatSessions(
  input: { page?: number; perPage?: number } = {},
  options?: WorkflowApiRequestOptions
): Promise<ChatSessionsPage> {
  return agentApi().listChatSessions(input, options);
}

export function purgeChatSessions(
  input: { olderThanDays?: number } = {},
  options?: WorkflowApiRequestOptions
): Promise<ChatSessionsPurgeResult> {
  return agentApi().purgeChatSessions(input, options);
}

export function createChatSession(input: { label?: string; workflowId?: Workflow['id'] }, options?: WorkflowApiRequestOptions): Promise<ChatSession> {
  return agentApi().createChatSession(input, options);
}

export function getChatSession(id: string, options?: WorkflowApiRequestOptions): Promise<ChatSession> {
  return agentApi().getChatSession(id, options);
}

export function patchChatSession(
  id: string,
  input: { label?: string; workflowId?: Workflow['id'] | null },
  options?: WorkflowApiRequestOptions
): Promise<ChatSession> {
  return agentApi().patchChatSession(id, input, options);
}

export function deleteChatSession(id: string, options?: WorkflowApiRequestOptions): Promise<{ deleted: boolean; id: string }> {
  return agentApi().deleteChatSession(id, options);
}

export function appendChatMessages(id: string, messages: ChatSessionMessage[], options?: WorkflowApiRequestOptions): Promise<ChatSession> {
  return agentApi().appendChatMessages(id, messages, options);
}

function errorFromResponse(response: Response, endpoint: string, method: string, payload: unknown): WorkflowApiError {
  const payloadObject = isRecord(payload) ? payload : {};
  const message =
    typeof payloadObject.message === 'string'
      ? payloadObject.message
      : `Workflow API ${method} ${endpoint} failed with HTTP ${response.status}.`;
  const code =
    typeof payloadObject.code === 'string'
      ? payloadObject.code
      : response.status === 401 || response.status === 403
        ? 'permission_denied'
        : `http_${response.status}`;

  return new WorkflowApiError({
    message,
    status: response.status,
    code,
    endpoint,
    method,
    payload
  });
}

function normalizeWorkflowApiError(error: unknown, endpoint: string, method: string): WorkflowApiError {
  if (error instanceof WorkflowApiError) {
    return error;
  }

  if (error instanceof DOMException && error.name === 'AbortError') {
    return new WorkflowApiError({
      message: 'Workflow API request was aborted.',
      status: 0,
      code: 'aborted',
      endpoint,
      method,
      payload: error
    });
  }

  return new WorkflowApiError({
    message: error instanceof Error ? error.message : 'Workflow API request failed.',
    status: 0,
    code: 'network_error',
    endpoint,
    method,
    payload: error
  });
}

function shouldRetry(error: WorkflowApiError): boolean {
  return error.status === 0 || error.status === 429 || error.status >= 500;
}

function pushTimeline(item: WorkflowApiTimelineItem): void {
  workflowApiTimeline.push(item);
}

function delay(ms: number): Promise<void> {
  if (ms === 0) {
    return Promise.resolve();
  }

  return new Promise((resolve) => globalThis.setTimeout(resolve, ms));
}

function timelineId(): string {
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isEmptyPlainObject(value: unknown): boolean {
  return isRecord(value) && Object.keys(value).length === 0;
}

