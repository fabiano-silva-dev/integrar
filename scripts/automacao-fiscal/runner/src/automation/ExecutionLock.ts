import { AutomationError } from '../errors/AutomationError.js';

export class ExecutionLock {
  #busy = false;
  #owner: string | null = null;

  tryAcquire(owner: string): void {
    if (this.#busy) {
      throw new AutomationError('RUNNER_BUSY', `Execução em andamento: ${this.#owner ?? 'unknown'}`, {
        metadata: { currentOwner: this.#owner },
      });
    }
    this.#busy = true;
    this.#owner = owner;
  }

  release(owner: string): void {
    if (this.#owner === owner) {
      this.#busy = false;
      this.#owner = null;
    }
  }

  get isBusy(): boolean {
    return this.#busy;
  }

  get currentOwner(): string | null {
    return this.#owner;
  }
}

export const globalExecutionLock = new ExecutionLock();
