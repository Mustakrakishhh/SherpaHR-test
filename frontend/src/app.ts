import { afterNextRender, Component, computed, ElementRef, inject, Injector, signal, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Api } from './api';
import { AttentionOverview, AttentionReason, Comment, Context, Project, Task, TaskDetail, TaskStatus } from './models';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.html',
})
export class AppComponent {
  api = inject(Api);
  private injector = inject(Injector);
  @ViewChild('detailFeedback') private detailFeedback?: ElementRef<HTMLElement>;
  context = signal<Context | null>(null);
  projects = signal<Project[]>([]);
  project = signal<Project | null>(null);
  tasks = signal<Task[]>([]);
  detail = signal<TaskDetail | null>(null);
  view = signal<'overview' | 'project'>('overview');
  overview = signal<AttentionOverview | null>(null);
  overviewLoading = signal(false);
  overviewError = signal('');
  scope = signal<'attention' | 'all'>('attention');
  reason = signal<AttentionReason | ''>('');
  attentionSearch = signal('');
  ownedProjects = computed(() => this.projects().filter(p => !p.archived && p.owner_id === this.context()?.user.id));
  attentionTasks = computed(() => this.overview()?.tasks.filter(t => t.reasons.length > 0) ?? []);
  visibleAttentionTasks = computed(() => {
    const query = this.attentionSearch().trim().toLocaleLowerCase('ru');
    const reason = this.reason();
    return (this.overview()?.tasks ?? []).filter(task =>
      (this.scope() === 'all' || task.reasons.length > 0)
      && (!reason || task.reasons.includes(reason))
      && (!query || [task.title, task.project_name, task.description, task.last_comment?.body ?? '']
        .some(text => text.toLocaleLowerCase('ru').includes(query))));
  });
  // An overview card can belong to a different project from the last project screen.
  detailProject = computed(() => {
    const id = this.detail()?.project_id ?? (this.creating() ? this.project()?.id : null);
    return this.projects().find(p => p.id === id) ?? null;
  });
  loading = signal(false);
  detailLoading = signal(false);
  saving = signal(false);
  error = signal('');
  notice = signal('');
  status = '';
  search = '';
  comment = '';
  helpRequested = false;
  creating = signal(false);
  draft = this.emptyDraft();
  private generation = 0;
  private detailGeneration = 0;
  private overviewGeneration = 0;
  readonly reasons: { value: AttentionReason; label: string }[] = [
    { value: 'blocked', label: 'Заблокирована' },
    { value: 'overdue', label: 'Просрочена' },
    { value: 'unassigned', label: 'Нет исполнителя' },
    { value: 'discussion', label: 'Просьба о помощи' },
  ];
  readonly statuses: { value: TaskStatus; label: string }[] = [
    { value: 'open', label: 'Открыта' }, { value: 'in_progress', label: 'В работе' },
    { value: 'blocked', label: 'Заблокирована' }, { value: 'done', label: 'Готово' },
  ];

  constructor() { void this.load(); }

  async load(): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.closeDetail();
    try {
      const [context, projects] = await Promise.all([
        this.api.request<Context>('/context'), this.api.request<Project[]>('/projects'),
      ]);
      if (generation !== this.generation) return;
      this.context.set(context);
      this.projects.set(projects);
      await this.refreshOverview();
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  switchUser(value: string): void {
    this.api.userId = Number(value);
    ++this.overviewGeneration;
    this.overviewLoading.set(false);
    this.project.set(null);
    this.projects.set([]);
    this.tasks.set([]);
    this.context.set(null);
    this.overview.set(null);
    this.overviewError.set('');
    this.view.set('overview');
    this.setScope('attention');
    this.attentionSearch.set('');
    this.notice.set('');
    void this.load();
  }

  showOverview(): void {
    ++this.generation;
    this.loading.set(false);
    this.view.set('overview');
    this.error.set('');
    this.notice.set('');
    this.closeDetail();
    void this.refreshOverview();
  }

  async refreshOverview(): Promise<void> {
    const generation = ++this.overviewGeneration;
    const userId = this.api.userId;
    this.overviewLoading.set(true);
    this.overviewError.set('');
    try {
      const overview = await this.api.request<AttentionOverview>('/attention');
      if (generation === this.overviewGeneration && userId === this.api.userId) this.overview.set(overview);
    } catch (error) {
      if (generation === this.overviewGeneration && userId === this.api.userId) {
        this.overviewError.set(error instanceof Error ? error.message : 'Не удалось обновить обзор');
      }
    } finally {
      if (generation === this.overviewGeneration) this.overviewLoading.set(false);
    }
  }

  setScope(scope: 'attention' | 'all'): void {
    this.scope.set(scope);
    this.reason.set('');
  }

  reasonCount(reason: AttentionReason): number {
    return this.attentionTasks().filter(task => task.reasons.includes(reason)).length;
  }

  async selectProject(project: Project): Promise<void> {
    const generation = ++this.generation;
    this.view.set('project');
    this.loading.set(true);
    this.error.set('');
    this.notice.set('');
    this.project.set(project);
    this.tasks.set([]);
    this.status = '';
    this.search = '';
    this.closeDetail();
    try {
      const tasks = await this.api.request<Task[]>(`/projects/${project.id}/tasks`);
      if (generation === this.generation) this.tasks.set(tasks);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  filteredTasks(): Task[] {
    return this.tasks().filter(task => (!this.status || task.status === this.status)
      && task.title.toLocaleLowerCase('ru').includes(this.search.toLocaleLowerCase('ru')));
  }

  async openTask(task: Task): Promise<void> {
    const generation = ++this.detailGeneration;
    this.error.set('');
    this.notice.set('');
    this.creating.set(false);
    this.detail.set(null);
    this.comment = '';
    this.helpRequested = false;
    this.detailLoading.set(true);
    try {
      const detail = await this.api.request<TaskDetail>(`/tasks/${task.id}`);
      if (generation !== this.detailGeneration) return;
      this.detail.set(detail);
      this.draft = {
        title: detail.title, description: detail.description, status: detail.status,
        assignee_id: detail.assignee_id, due_at: detail.due_at?.slice(0, 16) ?? '',
      };
    } catch (error) {
      if (generation === this.detailGeneration) this.fail(error);
    } finally {
      if (generation === this.detailGeneration) this.detailLoading.set(false);
    }
  }

  closeDetail(): void {
    ++this.detailGeneration;
    this.detail.set(null);
    this.detailLoading.set(false);
    this.creating.set(false);
    this.comment = '';
    this.helpRequested = false;
  }

  createTask(): void {
    this.closeDetail();
    this.draft = this.emptyDraft();
    this.creating.set(true);
  }

  async saveTask(): Promise<void> {
    const project = this.detailProject();
    if (!project || project.archived || this.saving()) return;
    const userId = this.api.userId;
    const generation = this.detailGeneration;
    const existing = this.detail();
    this.saving.set(true);
    this.error.set('');
    this.notice.set('');
    try {
      const data = {
        ...this.draft,
        due_at: this.draft.due_at ? `${this.draft.due_at}:00Z` : null,
        ...(existing ? { version: existing.version } : {}),
      };
      const task = await this.api.request<Task>(existing ? `/tasks/${existing.id}` : `/projects/${project.id}/tasks`, existing ? 'PATCH' : 'POST', data);
      if (userId !== this.api.userId) return;
      if (!existing) this.projects.update(list => list.map(p => p.id === project.id ? { ...p, task_count: p.task_count + 1 } : p));
      if (this.project()?.id === project.id) {
        this.tasks.update(list => existing ? list.map(t => t.id === task.id ? task : t) : [...list, task]);
      }
      if (generation === this.detailGeneration) {
        // Saving fields must not erase a comment the user has not sent yet.
        this.detail.set({ ...task, comments: existing?.comments ?? [] });
        this.creating.set(false);
        if (task.status === 'done') this.helpRequested = false;
        this.draft = {
          title: task.title, description: task.description, status: task.status,
          assignee_id: task.assignee_id, due_at: task.due_at?.slice(0, 16) ?? '',
        };
        this.notice.set('Задача сохранена');
      }
      await this.refreshOverview();
    } catch (error) {
      if (userId === this.api.userId && generation === this.detailGeneration) this.fail(error);
    } finally { this.saving.set(false); }
  }

  async addComment(): Promise<void> {
    const task = this.detail();
    if (!task || this.detailProject()?.archived || this.saving()) return;
    const generation = this.detailGeneration;
    const userId = this.api.userId;
    this.saving.set(true);
    this.error.set('');
    this.notice.set('');
    try {
      const comment = await this.api.request<Comment>(`/tasks/${task.id}/comments`, 'POST', { body: this.comment, help_requested: this.helpRequested });
      if (userId !== this.api.userId) return;
      if (generation === this.detailGeneration) {
        // Refresh only the discussion: fields may contain unsaved user edits.
        this.detail.update(current => current ? { ...current, comments: [...current.comments, comment] } : current);
        this.comment = '';
        this.helpRequested = false;
        this.notice.set('Комментарий добавлен');
      }
      await this.refreshOverview();
    } catch (error) {
      if (userId === this.api.userId && generation === this.detailGeneration) this.fail(error);
    } finally { this.saving.set(false); }
  }

  async setRequestHandled(comment: Comment, handled: boolean): Promise<void> {
    const task = this.detail();
    if (!task || this.saving() || this.detailProject()?.archived
      || this.detailProject()?.owner_id !== this.api.userId) return;
    const generation = this.detailGeneration;
    const userId = this.api.userId;
    this.saving.set(true);
    this.error.set('');
    this.notice.set('');
    try {
      const updated = await this.api.request<Comment>(`/tasks/${task.id}/comments/${comment.id}/attention`, 'PATCH', {
        handled, version: comment.attention_version,
      });
      if (userId !== this.api.userId) return;
      if (generation === this.detailGeneration) {
        // Only replace the request marker. Keep draft fields and unsent comment intact.
        this.detail.update(current => current ? {
          ...current, comments: current.comments.map(c => c.id === updated.id ? updated : c),
        } : current);
        this.notice.set(handled ? 'Просьба отмечена обработанной' : task.status === 'done'
          ? 'Отметка обработки снята. Для появления просьбы в обзоре переоткройте задачу.'
          : 'Просьба снова требует внимания');
      }
      await this.refreshOverview();
    } catch (error) {
      if (userId === this.api.userId && generation === this.detailGeneration) this.fail(error);
    } finally { this.saving.set(false); }
  }

  reasonLabel(reason: AttentionReason): string { return this.reasons.find(r => r.value === reason)?.label ?? reason; }
  statusLabel(status: string): string { return this.statuses.find(s => s.value === status)?.label ?? status; }
  userName(id: number | null): string { return this.context()?.users.find(u => u.id === id)?.name ?? 'Не назначен'; }
  private fail(error: unknown): void {
    this.error.set(error instanceof Error ? error.message : 'Не удалось выполнить запрос');
    const generation = this.detailGeneration;
    // Wait for Angular to render the message before focusing it. Focus also brings
    // an error into view when the user submitted from the bottom of a long card.
    afterNextRender(() => {
      if (generation === this.detailGeneration) this.detailFeedback?.nativeElement.focus();
    }, { injector: this.injector });
  }
  private emptyDraft(): { title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string } {
    return { title: '', description: '', status: 'open', assignee_id: null, due_at: '' };
  }
}
