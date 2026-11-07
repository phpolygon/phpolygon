#version 410 core
// Sky element 5/6 from the cloud cache (shader plan 9.1, GraphicsSettings::$skyCloudCache):
// the raymarched clouds were rendered into a cube a moment ago (linear colour, straight
// alpha); here they are only looked up and alpha-blended over sun, moon and stars.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform samplerCube u_cloud_cache;
uniform int u_linear_output;
#ifdef PHPOLYGON_MRT
layout(location = 0) out vec4 o_sun;
layout(location = 1) out vec4 o_local;
layout(location = 2) out vec4 o_ambient;
layout(location = 3) out vec4 o_gbuffer;
#define frag_color o_local
#else
out vec4 frag_color;
#endif

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);
    vec4 c = texture(u_cloud_cache, dir);
    vec3 col = u_linear_output == 1 ? c.rgb : pow(max(c.rgb, vec3(0.0)), vec3(1.0 / 2.2));
    frag_color = vec4(col, c.a);
}
