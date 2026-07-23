export type {
  AutomationMode,
  AutomationResult,
  AutomationContext,
  AutomationEvent,
  PortalAdapter,
  SuccessDetection,
  RunStatus,
} from './types.js';
export { AutomationRunner } from './AutomationRunner.js';
export { ExecutionLock, globalExecutionLock } from './ExecutionLock.js';
export { PlatformClient } from './PlatformClient.js';
export { BrowserManager } from './BrowserManager.js';
export { runFakeMode } from './FakeModeRunner.js';
