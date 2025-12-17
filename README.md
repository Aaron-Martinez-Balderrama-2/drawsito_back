# Backend API - Spring Boot

Este módulo contiene la lógica de negocio y la capa de acceso a datos del sistema. Expone una API RESTful para ser consumida por clientes móviles o web.

## 📋 Ficha Técnica

* **Lenguaje:** Java 17 (OpenJDK 17+)
* **Framework:** Spring Boot 3.3.3
* **Gestor de Dependencias:** Maven
* **Documentación API:** SpringDoc OpenAPI (Swagger UI disponible en `/swagger-ui.html` cuando corre en local).

## 🔧 Configuración Clave

### Estructura de Carpetas
* `entidad/`: Modelos de datos (POJOs) mapeados a la BD con JPA. Incluye anotaciones `@JsonProperty` para asegurar la compatibilidad con el JSON del frontend.
* `repo/`: Interfaces `JpaRepository` para operaciones CRUD directas.
* `controller/`: Controladores REST que manejan las peticiones HTTP (GET, POST, PUT, DELETE).

### Fixes Aplicados (v3.5+)
* **Tablas:** Se utiliza `@Table(name="\"Nombre\"")` para respetar la sensibilidad a mayúsculas de PostgreSQL.
* **IDs:** Estrategia de generación `IDENTITY` para delegar el autoincremento a la base de datos (`bigserial`).
* **Mapeo:** Uso estricto de Jackson para alinear nombres de variables (camelCase vs snake_case).

## ▶️ Comandos Útiles

**Iniciar servidor:**
```bash
mvn spring-boot:run