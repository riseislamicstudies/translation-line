-- Update 19: keep Word formatting. Inline markers live in the text (**bold**, *italic*, [^n] footnote reference,
-- [text](url) link); headings keep their level; footnotes are their own segments.
alter type public.segment_kind add value if not exists 'footnote';
alter table public.segments add column if not exists level smallint;

create or replace function public.writer_submit(p_id uuid, p_title text, p_filename text, p_path text, p_segments jsonb, p_replaces uuid default null)
returns uuid language plpgsql security definer set search_path to 'public' as $function$
declare
  n int; wc int;
begin
  if public.my_role()::text <> all (array['writer', 'journal_writer']) then
    raise exception 'not_allowed' using errcode = '42501';
  end if;
  if p_id is null or p_title is null or length(trim(p_title)) = 0 then
    raise exception 'invalid_input';
  end if;
  if p_path is not null and p_path <> p_id::text || '/source.docx' then
    raise exception 'invalid_input';
  end if;
  if p_segments is null or jsonb_typeof(p_segments) <> 'array' then
    raise exception 'invalid_input';
  end if;
  n := jsonb_array_length(p_segments);
  if n < 1 or n > 2000 then
    raise exception 'invalid_input';
  end if;
  if exists (select 1 from jsonb_array_elements(p_segments) e
              where jsonb_typeof(e) <> 'object'
                 or coalesce(e ->> 'text', '') = ''
                 or length(e ->> 'text') > 30000
                 or coalesce(e ->> 'kind', 'paragraph') not in ('heading', 'paragraph', 'list_item', 'footnote')) then
    raise exception 'invalid_input';
  end if;
  if (select count(*) from public.documents
       where uploaded_by = auth.uid() and created_at > now() - interval '1 hour') >= 20 then
    raise exception 'rate_limited';
  end if;
  if p_replaces is not null and not exists (
       select 1 from public.documents d
        where d.id = p_replaces and d.uploaded_by = auth.uid()
          and d.status = 'review_rejected' and d.replaced_by is null) then
    raise exception 'cannot_replace';
  end if;

  select coalesce(sum(coalesce(array_length(regexp_split_to_array(trim(e ->> 'text'), '\s+'), 1), 0)), 0)
    into wc from jsonb_array_elements(p_segments) e;

  insert into public.documents (id, title, source_filename, source_path, word_count, uploaded_by, source_kind)
  values (p_id, left(trim(p_title), 300), left(p_filename, 300), p_path, wc, auth.uid(), case when public.my_role()::text = 'journal_writer' then 'journal' else 'scholaria' end);

  insert into public.segments (document_id, position, kind, source_text, level)
  select p_id, o.ord::int, coalesce(o.e ->> 'kind', 'paragraph')::public.segment_kind, o.e ->> 'text',
         case when (o.e ->> 'level') ~ '^[1-6]$' then (o.e ->> 'level')::smallint end
    from jsonb_array_elements(p_segments) with ordinality as o(e, ord);

  if p_replaces is not null then
    update public.documents set replaced_by = p_id where id = p_replaces;
  end if;

  perform public.notify_role('reviewer'::public.app_role, 'needs_review', p_id,
    jsonb_build_object('title', left(trim(p_title), 300),
                       'by', (select full_name from public.profiles where id = auth.uid())));
  return p_id;
end $function$;
