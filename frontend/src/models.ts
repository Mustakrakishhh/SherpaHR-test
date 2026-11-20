export interface User { id: number; name: string }
export interface Project { id: number; name: string; description: string; archived: boolean; owner_id: number; task_count: number; members: User[] }
export type TaskStatus = 'open' | 'in_progress' | 'blocked' | 'done';
export interface Task { id: number; project_id: number; title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string | null; updated_at: string; version: number }
export interface Comment {
  id: number; task_id: number; user_id: number; body: string; created_at: string; author: string;
  help_requested: boolean; is_request: boolean; matched_phrase: string | null;
  handled: boolean; handled_at: string | null; handled_by: number | null;
  handled_by_name: string | null; attention_version: number;
}
export interface TaskDetail extends Task { comments: Comment[] }
export interface Context { user: User; users: User[]; server_now: string; seeded_at: string }
export type AttentionReason = 'blocked' | 'overdue' | 'unassigned' | 'discussion';
export interface AttentionTask extends Task {
  project_name: string;
  reasons: AttentionReason[];
  last_comment: Comment | null;
  comment_count: number;
  discussion_signal: { comment: Comment; matched_phrase: string | null; explicit: boolean } | null;
  open_request_count: number;
}
export interface AttentionOverview { generated_at: string; tasks: AttentionTask[] }
