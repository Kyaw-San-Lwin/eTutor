USE etutor;

-- Soft delete columns for core entities
ALTER TABLE blog_posts ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

-- Helpful indexes
CREATE INDEX IF NOT EXISTS idx_blog_posts_deleted_at ON blog_posts (deleted_at);
CREATE INDEX IF NOT EXISTS idx_documents_deleted_at ON documents (deleted_at);
