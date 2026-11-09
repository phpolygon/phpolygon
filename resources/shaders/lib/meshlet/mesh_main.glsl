
layout(local_size_x = @GROUP@) in;
layout(triangles, max_vertices = @MAX_VERTICES@, max_primitives = @MAX_TRIANGLES@) out;
void main() {
    uint mb = ms_payload.meshlets[gl_WorkGroupID.x] * 8u;
    uint vOff = ms_meshlets[mb];
    uint vCnt = ms_meshlets[mb + 1u];
    uint tOff = ms_meshlets[mb + 2u];
    uint tCnt = ms_meshlets[mb + 3u];
    SetMeshOutputsEXT(vCnt, tCnt);
    uint li = gl_LocalInvocationIndex;
    if (li < vCnt) {
        uint vi = ms_indices[vOff + li];
        uint vb = vi * 8u;
        a_position = vec3(ms_vertices[vb], ms_vertices[vb + 1u], ms_vertices[vb + 2u]);
        a_normal = vec3(ms_vertices[vb + 3u], ms_vertices[vb + 4u], ms_vertices[vb + 5u]);
        a_uv = vec2(ms_vertices[vb + 6u], ms_vertices[vb + 7u]);
        uint ib = ms_payload.instance * 16u;
        a_instance_col0 = vec4(ms_instances[ib], ms_instances[ib + 1u], ms_instances[ib + 2u], ms_instances[ib + 3u]);
        a_instance_col1 = vec4(ms_instances[ib + 4u], ms_instances[ib + 5u], ms_instances[ib + 6u], ms_instances[ib + 7u]);
        a_instance_col2 = vec4(ms_instances[ib + 8u], ms_instances[ib + 9u], ms_instances[ib + 10u], ms_instances[ib + 11u]);
        a_instance_col3 = vec4(ms_instances[ib + 12u], ms_instances[ib + 13u], ms_instances[ib + 14u], ms_instances[ib + 15u]);
        ms_VertexID = int(vi);
        ms_InstanceID = int(ms_payload.instance);
        ms_vertexMain();
        gl_MeshVerticesEXT[li].gl_Position = ms_Position;
@COPIES@    }
    for (uint t = li; t < tCnt; t += @GROUP@u) {
        uint p = ms_indices[tOff + t];
        gl_PrimitiveTriangleIndicesEXT[t] = uvec3(p & 255u, (p >> 8u) & 255u, (p >> 16u) & 255u);
    }
}
