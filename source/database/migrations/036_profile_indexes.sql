-- Profile pages filter posts/topics by author.

CREATE INDEX IF NOT EXISTS idx_posts_user_created ON posts (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_topics_user_created ON topics (user_id, created_at DESC);
