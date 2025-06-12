-- WARNING: This schema is for context only and is not meant to be run.
-- Table order and constraints may not be valid for execution.

CREATE TABLE public.admins (
  id integer NOT NULL DEFAULT nextval('admins_id_seq'::regclass),
  nom character varying,
  email character varying UNIQUE,
  mot_de_passe character varying,
  est_super_admin boolean DEFAULT false,
  last_activity timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  langue_preferee character varying DEFAULT 'ar'::character varying,
  CONSTRAINT admins_pkey PRIMARY KEY (id)
);
CREATE TABLE public.agences (
  id integer NOT NULL DEFAULT nextval('agences_id_seq'::regclass),
  nom_agence character varying,
  wilaya character varying,
  telephone character varying,
  email character varying UNIQUE,
  mot_de_passe character varying,
  approuve boolean DEFAULT false,
  photo_profil character varying,
  photo_couverture character varying,
  date_creation timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
  date_modification timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
  last_activity timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
  commercial_license_number character varying,
  latitude double precision,
  longitude double precision,
  langue_preferee character varying DEFAULT 'ar'::character varying,
  CONSTRAINT agences_pkey PRIMARY KEY (id)
);
CREATE TABLE public.agences_secondaires (
  id integer NOT NULL DEFAULT nextval('agences_secondaires_id_seq'::regclass),
  agence_id integer NOT NULL,
  telephone character varying,
  mot_de_passe character varying,
  wilaya character varying,
  CONSTRAINT agences_secondaires_pkey PRIMARY KEY (id),
  CONSTRAINT fk_agence_id FOREIGN KEY (agence_id) REFERENCES public.agences(id)
);
CREATE TABLE public.chomber_images (
  id integer NOT NULL DEFAULT nextval('offre_images_id_seq'::regclass),
  offre_id integer,
  image_url character varying NOT NULL,
  CONSTRAINT chomber_images_pkey PRIMARY KEY (id),
  CONSTRAINT offre_images_offre_id_fkey FOREIGN KEY (offre_id) REFERENCES public.offres(id)
);
CREATE TABLE public.demande_umrah (
  id integer NOT NULL DEFAULT nextval('demande_umrah_id_seq'::regclass),
  nom character varying,
  telephone character varying,
  offre_id integer,
  statut character varying DEFAULT 'en_attente'::character varying CHECK (statut::text = ANY (ARRAY['en_attente'::character varying, 'accepte'::character varying, 'refuse'::character varying]::text[])),
  date_demande timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  passport_image character varying,
  wilaya character varying,
  CONSTRAINT demande_umrah_pkey PRIMARY KEY (id)
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
CREATE TABLE public.offer_images (
  id integer NOT NULL DEFAULT nextval('offer_images_id_seq'::regclass),
  offer_id integer NOT NULL,
  image character varying NOT NULL,
  created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT offer_images_pkey PRIMARY KEY (id)
);
CREATE TABLE public.offres (
  id integer NOT NULL DEFAULT nextval('offres_id_seq'::regclass),
  agence_id integer,
  titre character varying,
  description text,
  prix_base numeric,
  date_depart date,
  date_retour date,
  est_doree boolean DEFAULT false,
  service_guide boolean DEFAULT false,
  service_transport boolean DEFAULT false,
  service_nourriture boolean DEFAULT false,
  service_assurance boolean DEFAULT false,
  aeroport_depart character varying,
  compagnie_aerienne character varying,
  type_voyage character varying CHECK (type_voyage::text = ANY (ARRAY['Hajj'::character varying, 'Omra'::character varying]::text[])),
  nom_hotel character varying,
  numero_chambre character varying,
  distance_haram numeric,
  prix_2 numeric,
  prix_3 numeric,
  prix_4 numeric,
  cadeau_bag boolean DEFAULT false,
  cadeau_zamzam boolean DEFAULT false,
  cadeau_parapluie boolean DEFAULT false,
  cadeau_autre boolean DEFAULT false,
  image_offre_principale character varying,
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
  last_activity timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT sub_admins_pkey PRIMARY KEY (id),
  CONSTRAINT sub_admins_cree_par_admin_id_fkey FOREIGN KEY (cree_par_admin_id) REFERENCES public.admins(id)
);