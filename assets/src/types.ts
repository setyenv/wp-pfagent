export type WorkflowStatus = 'draft' | 'active' | 'paused' | 'archived';
export type WorkflowNodeType = 'trigger' | 'condition' | 'action' | 'transform' | 'delay' | 'loop' | 'batch' | 'note';
export type WorkflowConnectionKind = 'exec' | 'data';

export interface WorkflowPosition {
  x: number;
  y: number;
}

export interface WorkflowNodeDefinition {
  id: string;
  key: string;
  type: WorkflowNodeType;
  label: string;
  position: WorkflowPosition;
  data: Record<string, unknown>;
}

export interface WorkflowConnectionDefinition {
  id: string;
  source: string;
  sourceOutput: string;
  target: string;
  targetInput: string;
  kind?: WorkflowConnectionKind;
}

export interface WorkflowGraph {
  schemaVersion: 1;
  nodes: WorkflowNodeDefinition[];
  connections: WorkflowConnectionDefinition[];
  studio?: Record<string, unknown>;
  graphs?: Array<Record<string, unknown>>;
}

export interface Workflow {
  /** Opaque: PFW mints uuids. A legacy install may still send a number. */
  id: string | number;
  name: string;
  status: WorkflowStatus;
  graph: WorkflowGraph;
  createdAt: string;
  updatedAt: string;
}

export interface WorkflowTemplate {
  id: string;
  name: string;
  description: string;
  category: string;
  useCase?: string;
  difficulty?: 'starter' | 'standard' | 'advanced';
  estimatedSetupMinutes?: number;
  riskLevel?: 'low' | 'medium' | 'high';
  dependencies?: string[];
  outcomes?: string[];
  triggerSummary?: string[];
  tier?: 'free' | 'pro';
  graph: WorkflowGraph;
}

export interface WorkflowTemplateCatalog {
  templates: WorkflowTemplate[];
}

export interface ExecutionLog {
  id: string | number;
  workflowId: string | number;
  status: string;
  message: string;
  context: Record<string, unknown>;
  createdAt: string;
}

export interface WorkflowValidationResult {
  valid: boolean;
  message: string;
}

export interface WorkflowRunResult {
  status: string;
  message?: string;
  workflowId?: string | number;
  executionId?: string;
  [key: string]: unknown;
}

export interface AppConfig {
  restUrl: string;
  workflowRestUrl: string;
  nonce: string;
  version: string;
  /**
   * Absolute URL of the wp-admin entry. The full-screen shell renders the
   * SPA outside the standard wp-admin chrome, so the "Back to admin"
   * affordance has to navigate explicitly.
   */
  adminUrl: string;
  iconUrl?: string;
  /** This plugin's display name (from its own "Plugin Name" header) — drives
   *  the SPA header title, so each build shows its own name. */
  name?: string;
  /** Setyenv vendor logo URL, shown top-right in the header (links setyenv.com). */
  setyenvLogoUrl?: string;
  /** Sibling products for the header suite switcher (hamburger). Admin-gated
   *  on the PHP side: empty for non-admins, so the SPA hides the hamburger.
   *  Each entry is {slug,label,url}; WP Admin closes the list. */
  products?: Array<{ slug: string; label: string; url: string }>;
  workflowDependency: {
    active: boolean;
    namespace: string;
    restUrl: string;
    adminUrl: string;
    source: 'workflow_constant' | 'fallback';
    capabilities: {
      viewWorkflows: boolean;
      manageWorkflows: boolean;
      runWorkflows: boolean;
      viewLogs: boolean;
    };
  };
  managementDependency: {
    active: boolean;
    namespace: string;
    restUrl: string;
    adminUrl: string;
    source: 'management_constant' | 'fallback';
  };
  /**
   * Server-persisted active LLM selection (provider + model). Single source
   * of truth: lives in the wp_pfagent_active_llm option, hydrated here on
   * every page load so the SPA never touches localStorage for this slot.
   * Empty strings mean "no selection yet".
   */
  activeLlm: {
    providerId: string;
    model: string;
    sessionId: string | null;
    updatedAt: string;
  };
  capabilities: {
    manageAgent: boolean;
    manageCredentials: boolean;
    viewWorkflows: boolean;
    manageWorkflows: boolean;
    runWorkflows: boolean;
    viewLogs: boolean;
  };
}

export interface ChatMessage {
  id: string;
  role: 'user' | 'assistant' | 'system';
  text: string;
  at: string;
  /** Tool calls executed by the agent runtime during this turn. */
  executions?: AgentRuntimeExecution[];
  /** Side-effect confirmation pending the user's approval. */
  pending?: { confirmationId: string; toolName: string; args: Record<string, unknown> };
  /** Error code returned by the runtime for this turn (if any). */
  errorCode?: string;
}

export type ProviderCredentialState = 'not_configured' | 'configured_unvalidated' | 'validated' | 'validation_failed';

export interface ProviderFamily {
  label: string;
  auth: string;
  defaultHeaders?: Record<string, string>;
  defaults?: Record<string, string>;
  endpoints: Record<string, string>;
}

export interface ProviderPreset {
  label: string;
  family: string;
  baseUrl: string;
  modelDiscovery: string;
  modelHints: string[];
  status: string;
}

export interface ProviderPresetCatalog {
  schemaVersion: 1;
  families: Record<string, ProviderFamily>;
  presets: Record<string, ProviderPreset>;
}

export interface ProviderCredentialStatus {
  providerId: string;
  label: string;
  family: string;
  status: ProviderCredentialState;
  configured: boolean;
  maskedKey: string | null;
  settings: Record<string, string>;
  /**
   * Per-model user-confirmed configuration for this credential
   * (caps + pricing + features the wizard collected from API discovery
   * plus the user's manual fills). Empty array when the wizard hasn't
   * persisted anything yet.
   */
  models: ProviderModel[];
  configuredAt: string | null;
  updatedAt: string | null;
  validatedAt: string | null;
  validationMessage: string;
}

export interface ProviderCredentialCatalog {
  credentials: ProviderCredentialStatus[];
}

export interface ProviderGenerationResult {
  providerId: string;
  label: string;
  family: string;
  model: string;
  status: 'completed';
  prompt: string;
  output: string;
  usage: Record<string, unknown>;
  endpointType: string;
}

export interface LlmToolDefinition {
  name: string;
  description: string;
  parameters: Record<string, unknown>;
}

export interface AgentToolEndpoint {
  method: string;
  path: string;
  documentedAs: string;
}

export interface AgentToolContract extends LlmToolDefinition {
  capabilityKeys: string[];
  permission: string;
  sideEffect: boolean;
  endpoints: AgentToolEndpoint[];
  tests: string[];
}

export interface AgentToolCatalog {
  tools: AgentToolContract[];
}

export interface AgentInternalDocs {
  schema: 'projectflash.agent.internal_docs';
  schemaVersion: 1;
  generatedAt: string;
  source: 'generated_from_runtime_contract';
  summary: {
    routeCount: number;
    capabilityCount: number;
    agentToolCount: number;
    providerPresetCount: number;
    workflowActive: boolean;
  };
  sections: Array<{ id: string; title: string; lines: string[] }>;
  markdown: string;
  secretsIncluded: boolean;
}

export interface AgentFixSuggestion {
  id: string;
  severity: 'low' | 'medium' | 'high';
  category: string;
  title: string;
  rationale: string;
  actions: string[];
  evidence: string[];
}

export interface AgentFixSuggestionsRequest {
  error?: Record<string, unknown>;
  timeline?: Array<{ event?: string; at?: string; data?: Record<string, unknown>; [key: string]: unknown }>;
  tool?: string | { name?: string; arguments?: Record<string, unknown> };
  evidence?: Record<string, unknown>;
  result?: Record<string, unknown>;
}

export interface AgentFixSuggestionsResult {
  schema: 'projectflash.agent.fix_suggestions';
  schemaVersion: 1;
  generatedAt: string;
  status: 'suggestions_ready';
  inputSummary: {
    tool: string;
    signalCount: number;
  };
  suggestions: AgentFixSuggestion[];
}


export type AgentRuntimeChangeType = 'created' | 'updated' | 'imported';

export interface AgentWorkflowSnapshot {
  id: string | number;
  name: string;
  status: string;
  graph: WorkflowGraph | null;
  updatedAt: string;
}

export interface AgentRuntimeDiff {
  before: AgentWorkflowSnapshot | null;
  after: AgentWorkflowSnapshot | null;
  changeType: AgentRuntimeChangeType;
  changed: boolean;
}

export interface AgentRuntimeExecution {
  tool: { name: string; arguments: Record<string, unknown> };
  evidence: Record<string, unknown>;
  result: unknown;
  diff: AgentRuntimeDiff | null;
  startedAt: string;
  endedAt: string;
  durationMs: number;
  // 'error' executions surface failed tool calls so the LLM (and the
  // user) sees what went wrong without aborting the turn. The runtime
  // populates errorCode/errorMessage on those.
  status: 'success' | 'error';
  errorCode?: string;
  errorMessage?: string;
}

export interface AgentRuntimeProgressTool {
  /** The call's place in this conversation — what the poll advances on. */
  seq: number;
  tool: string;
  status: string;
  at: string;
  /** Compact focus payload extracted from the call's arguments +
   *  result so the frontend can compute the matching iframe target
   *  (pfm tab on kind/ref, pfw tab on workflowId/path) live as each
   *  tool finishes — not only at end-of-turn. */
  focus?: {
    kind?: string;
    ref?: string;
    path?: string;
    /** Whatever PFW mints — a uuid today, a number on a legacy install. */
    workflowId?: string | number;
    /** Parallel channel for the transversal WordPress layer: the native
     *  wp-admin screen a wp_*, wc_*, seo_*, forms_* tool maps to, so the
     *  "WordPress" tab can jump its iframe live. Additive - never replaces
     *  workflowId. Shape mirrors WpTarget in wpTarget.ts. */
    wpTarget?: {
      screen: 'edit' | 'terms' | 'upload' | 'users' | 'comments' | 'menus' | 'widgets' | 'options' | 'plugins' | 'site' | 'admin_page';
      postType?: string;
      id?: number;
      page?: string;
      /** `terms` screen: the taxonomy slug (edit-tags.php?taxonomy=…). */
      taxonomy?: string;
      /** `admin_page`: extra deep-link query params (e.g. Fluent Forms entries). */
      query?: Record<string, string>;
    };
  };
}

export interface AgentRuntimeProgressTrace {
  seq: number;
  kind: string;
  at: string;
}

export interface AgentRuntimeProgressNarration {
  /** wp_pfaf_messages.ordinal — stable per-conversation cursor the
   *  frontend uses to dedupe against the end-of-turn assistantTexts
   *  payload (a narration that already surfaced live shouldn't
   *  re-appear when the turn finally returns). */
  ordinal: number;
  content: string;
  at: string;
}

export interface AgentRuntimeProgress {
  conversationId: string;
  tools: AgentRuntimeProgressTool[];
  traces: AgentRuntimeProgressTrace[];
  /** New non-empty assistant rows the Loop persisted since the
   *  caller's sinceMessageOrdinal cursor. Lets the chat stream
   *  mid-loop narrations live instead of bursting them at end-of-
   *  turn (the "borbotones" bug). */
  assistantTexts: AgentRuntimeProgressNarration[];
  lastToolCallSeq: number;
  lastTraceSeq: number;
  /** Maximum ordinal seen on the message table (including empty
   *  rows). Pass back as sinceMessageOrdinal on the next poll. */
  lastMessageOrdinal: number;
}

export interface AgentRuntimeTurnResult {
  status: 'completed' | 'needs_confirmation' | 'rejected' | 'completed_with_response_error' | 'paused';
  traceId?: string;
  message: string;
  /** Every non-empty assistant text the Loop persisted during this
   *  turn, in order. Frontend renders each as a separate chat
   *  bubble so the live view matches rehydration — no narration is
   *  hidden until reload. Carries `ordinal` so the live polling
   *  stream and the end-of-turn payload can dedupe against each
   *  other. */
  assistantTexts?: Array<{ ordinal: number; content: string; at: string }>;
  confirmationId?: string;
  tool: null | { name: string; arguments: Record<string, unknown> };
  tools?: Array<{ name: string; arguments: Record<string, unknown> }>;
  evidence: Record<string, unknown>;
  result?: unknown;
  results?: unknown[];
  executions?: AgentRuntimeExecution[];
  timeline: Array<{ event: string; at: string; data: Record<string, unknown> }>;
  /** Populated when the runtime ran tools but the final LLM `complete`
   *  call failed (status === 'completed_with_response_error'). */
  /** Classified by the server: `code` is the provider-health vocabulary
   *  (auth / quota / rate_limit / invalid_model / provider_unavailable /
   *  network / configuration / provider_error), `message` a sentence the user
   *  can act on, `providerStatus` the HTTP status the provider answered with
   *  (0 when it never answered). The raw provider text stays in errorMessage. */
  llmError?: { code?: string; message?: string; providerStatus?: number };
  /** v2 path: per-turn token + cache + cost usage from the Framework Loop. */
  usage?: {
    promptTokens?: number;
    completionTokens?: number;
    totalTokens?: number;
    cacheHitTokens?: number;
    cacheMissTokens?: number;
    cacheWriteTokens?: number;
    reasoningTokens?: number;
  };
  conversationId?: string;
  finalText?: string;
  rounds?: number;
  costMicros?: number;
  /** H5: true when the turn paused on its wall-clock budget with work still
   *  pending. The client must POST /agent-runtime/continue-v2 to resume the
   *  same conversation — transparent, no user action. */
  continuation?: boolean;
}

export type ChatSessionRole = 'system' | 'user' | 'assistant';

export interface ChatSessionMessage {
  role: ChatSessionRole;
  content: string;
  at?: string;
  toolName?: string;
  turnId?: string;
  // F4: the tool executions this assistant turn produced, so a RELOADED
  // conversation re-renders its "N execution(s)" panel instead of losing it.
  // Populated by the session-load endpoint (grouped per assistant turn);
  // absent on older payloads, in which case the panel simply doesn't render
  // (the pre-existing behaviour).
  executions?: AgentRuntimeExecution[];
}

export interface ChatSessionSummary {
  /** uuid: the same value in every install, so a conversation can travel. */
  id: string;
  label: string;
  authorId: number;
  workflowId: string | number;
  turnCount: number;
  lastTurnAt: string;
  createdAt: string;
  updatedAt: string;
}

export interface ChatSession extends ChatSessionSummary {
  messages: ChatSessionMessage[];
}

export interface ChatSessionsPage {
  sessions: ChatSessionSummary[];
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
}

export interface ChatSessionsPurgeResult {
  deleted: number;
  olderThanDays: number;
  cutoff: string;
}

export type ProviderModelSource = 'api' | 'cache' | 'manual' | 'mixed';

/**
 * Discovery payload field set: superset of the on-disk per-credential
 * record (the user may not yet have saved pricing, etc.). The wizard
 * fills the blanks and POSTs back via {@link saveProviderModels}.
 */
export interface ProviderModelDefaults {
  temperature?: number;
  topP?: number;
  topK?: number;
  maxTemperature?: number;
}

export interface ProviderModelPricingTier {
  inputUpTo: number;
  input?: number;
  output?: number;
  cacheRead?: number;
  cacheWrite?: number;
}

export interface ProviderModelPricing {
  input?: number;
  output?: number;
  cacheRead?: number;
  cacheWrite?: number;
  tiers?: ProviderModelPricingTier[];
}

export interface ProviderModel {
  id: string;
  label: string;
  source: ProviderModelSource;
  family: string;
  capabilities: string[];
  contextLength?: number;
  maxOutputTokens?: number;
  minCacheTokens?: number;
  description?: string;
  version?: string;
  ownedBy?: string;
  createdAt?: string;
  features?: Record<string, boolean>;
  defaults?: ProviderModelDefaults;
  reasoningVariants?: string[];
  /**
   * Operator-picked reasoning depth for this model (one of
   * reasoningVariants, or a free-text value when the API doesnt expose
   * variants). Threaded into LoopOptions::reasoningEffort on every turn.
   */
  defaultReasoningEffort?: string;
  pricing?: ProviderModelPricing;
  /** DashScope-native: request/response modality lists (Text, Image, Audio, Video). */
  modalities?: { request: string[]; response: string[] };
  /** DashScope-native: canonical snapshot id this alias resolves to. */
  equivalentSnapshot?: string;
}

export interface ProviderBalance {
  available: boolean;
  currency: string;
  total: string;
  granted: string;
  toppedUp: string;
  fetchedAt: string;
}

export interface ProviderCatalogMetadata {
  balance?: ProviderBalance;
  warnings?: Array<{ shape?: string | null; message?: string }>;
}

export interface ProviderModelCatalog {
  providerId: string;
  label: string;
  family: string;
  modelDiscovery: string;
  manualAllowed: boolean;
  source: ProviderModelSource;
  fetchedAt: string;
  expiresAt: string | null;
  ttlSeconds: number | null;
  models: ProviderModel[];
  metadata?: ProviderCatalogMetadata;
}

export type ProviderHealthStatus = 'connected' | 'failed';
export type ProviderHealthErrorType =
  | 'auth'
  | 'quota'
  | 'network'
  | 'invalid_model'
  | 'rate_limit'
  | 'configuration'
  | 'provider_specific'
  | 'provider_error'
  | 'invalid_response'
  | 'manual_not_allowed'
  | null;

export interface ProviderHealthResult {
  providerId: string;
  label: string;
  family: string;
  status: ProviderHealthStatus;
  credentialStatus: ProviderCredentialState;
  errorType: ProviderHealthErrorType;
  httpStatus: number;
  checkedAt: string;
  message: string;
  modelsAvailable: number;
  discoverySource: ProviderModelSource | null;
}

export interface AgentContractRoute {
  method: string;
  path: string;
  permission: 'manageAgent' | 'manageCredentials' | string;
  sideEffect: boolean;
  response: string;
}

export interface AgentContract {
  schema: 'projectflash.agent.contract';
  schemaVersion: 1;
  generatedAt: string;
  plugin: {
    name: string;
    version: string;
    namespace: string;
  };
  permissions: Record<string, { wordpressCapability: string; description: string }>;
  routes: AgentContractRoute[];
  capabilityStates: string[];
  capabilities: Array<Record<string, unknown>>;
  agentTools: AgentToolContract[];
  providers: {
    schemaVersion: number;
    families: Record<string, unknown>;
    presets: Record<string, unknown>;
    credentialsIncluded: boolean;
    modelListsIncluded: boolean;
  };
  workflowDependency: {
    active: boolean;
    namespace: string;
    capabilities: Record<string, boolean>;
  };
  security: {
    secretsInContract: boolean;
    credentialValuesExposed: boolean;
    nonceRequired: boolean;
  };
}

export interface BetaReadinessCriterion {
  pass: boolean;
  description: string;
  violations: Array<string | Record<string, unknown>>;
}

export interface AgentMetricsTotals {
  byKind: Record<string, number>;
  byStatus: Record<string, number>;
  byProvider: Record<string, number>;
  totalRows: number;
  totalTokens: number;
  totalCostMicros: number;
  tokensByProvider: Record<string, number>;
  costMicrosByProvider: Record<string, number>;
}

export interface AgentMetricsResponse {
  schema: 'projectflash.agent.metrics';
  schemaVersion: 1;
  generatedAt: string;
  windowHours: number;
  since: string;
  totals: AgentMetricsTotals;
}

export interface BetaReadinessReport {
  schema: 'projectflash.agent.beta_readiness';
  schemaVersion: 1;
  generatedAt: string;
  ready: boolean;
  criteria: Record<string, BetaReadinessCriterion>;
  totals: Record<string, number>;
  partial: Array<{
    key: string;
    state: string;
    uiVisible: boolean;
    functional: boolean;
    notes: string;
  }>;
  providers: Array<Record<string, unknown>>;
  workflow: { active?: boolean; namespace?: string; capabilities?: Record<string, boolean> };
}

export interface AgentOpenApiDocument {
  openapi: '3.1.0';
  info: {
    title: string;
    version: string;
    description?: string;
  };
  servers: Array<{ url: string }>;
  paths: Record<string, Record<string, unknown>>;
  components: {
    securitySchemes: Record<string, unknown>;
    schemas: Record<string, unknown>;
  };
  security: Array<Record<string, unknown>>;
  'x-projectflash': {
    schema: 'projectflash.agent.openapi';
    schemaVersion: 1;
    generatedAt: string;
    capabilityCount: number;
    agentToolCount: number;
    secretsInContract: boolean;
    credentialValuesExposed: boolean;
  };
}

declare global {
  interface Window {
    ProjectFlashAgent?: AppConfig;
  }
}
