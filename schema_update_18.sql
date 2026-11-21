-- Update 18: profile photos keep the original upload.
-- Bucket 'avatars' (public): orig/<user>-<ts>.<ext> = original quality, thumb/<user>.jpg = small display copy.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('avatars', 'avatars', true, 20971520, array['image/jpeg','image/png','image/webp'])
on conflict (id) do update set public = true, file_size_limit = 20971520, allowed_mime_types = array['image/jpeg','image/png','image/webp'];
alter table public.profiles add column if not exists avatar_original_url text;
