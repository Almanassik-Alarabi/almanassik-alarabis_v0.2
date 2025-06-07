-- WARNING: This schema is for context only and is not meant to be run.
-- Table order and constraints may not be valid for execution.

CREATE TABLE public.admins (
  id integer NOT NULL DEFAULT nextval('admins_id_seq'::regclass),
  nom character varying,
  email character varying UNIQUE,
  mot_de_passe character varying,
  est_super_admin boolean DEFAULT false,
  CONSTRAINT admins_pkey PRIMARY KEY (id)
);
CREATE TABLE public.agences (
  id integer NOT NULL DEFAULT nextval('agences_id_seq'::regclass),
  nom_agence character varying,
  adresse character varying,
  telephone character varying,
  email character varying UNIQUE,
  mot_de_passe character varying,
  approuve boolean DEFAULT false,
  CONSTRAINT agences_pkey PRIMARY KEY (id)
);
CREATE TABLE public.demande_umrah (
  id integer NOT NULL DEFAULT nextval('demande_umrah_id_seq'::regclass),
  nom character varying,
  prenom character varying,
  email character varying,
  telephone character varying,
  offre_id integer,
  statut character varying DEFAULT 'en_attente'::character varying CHECK (statut::text = ANY (ARRAY['en_attente'::character varying, 'accepte'::character varying, 'refuse'::character varying]::text[])),
  admin_commentaire text,
  date_demande timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT demande_umrah_pkey PRIMARY KEY (id),
  CONSTRAINT demande_umrah_offre_id_fkey FOREIGN KEY (offre_id) REFERENCES public.offres(id)
);
CREATE TABLE public.messages (
  id integer NOT NULL DEFAULT nextval('messages_id_seq'::regclass),
  sender_type character varying NOT NULL CHECK (sender_type::text = ANY (ARRAY['admin'::character varying, 'agence'::character varying]::text[])),
  sender_id integer NOT NULL,
  receiver_id integer NOT NULL,
  message text NOT NULL,
  date_envoi timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT messages_pkey PRIMARY KEY (id)
);
CREATE TABLE public.offres (
  id integer NOT NULL DEFAULT nextval('offres_id_seq'::regclass),
  agence_id integer,
  titre character varying,
  description text,
  prix numeric,
  date_depart date,
  date_retour date,
  est_doree boolean DEFAULT false,
  CONSTRAINT offres_pkey PRIMARY KEY (id),
  CONSTRAINT offres_agence_id_fkey FOREIGN KEY (agence_id) REFERENCES public.agences(id)
);
CREATE TABLE public.sub_admin_permissions (
  id integer NOT NULL DEFAULT nextval('sub_admin_permissions_id_seq'::regclass),
  sub_admin_id integer NOT NULL,
  permission_key character varying NOT NULL,
  allow_view boolean DEFAULT false,
  allow_add boolean DEFAULT false,
  allow_edit boolean DEFAULT false,
  allow_delete boolean DEFAULT false,
  CONSTRAINT sub_admin_permissions_pkey PRIMARY KEY (id),
  CONSTRAINT sub_admin_permissions_sub_admin_id_fkey FOREIGN KEY (sub_admin_id) REFERENCES public.sub_admins(id)
);
CREATE TABLE public.sub_admins (
  id integer NOT NULL DEFAULT nextval('sub_admins_id_seq'::regclass),
  nom character varying,
  email character varying UNIQUE,
  mot_de_passe character varying,
  cree_par_admin_id integer,
  CONSTRAINT sub_admins_pkey PRIMARY KEY (id),
  CONSTRAINT sub_admins_cree_par_admin_id_fkey FOREIGN KEY (cree_par_admin_id) REFERENCES public.admins(id)
);